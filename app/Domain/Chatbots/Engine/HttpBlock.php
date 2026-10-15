<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use App\Domain\Developer\Services\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * API-call block: method, URL, headers and body with {{placeholders}} (API keys as
 * {{secret.NAME}}, kept on the server), timeout and retries. Maps response values into session
 * variables or contact fields, and branches Success (2xx) / Error (anything else, including a
 * timeout). Only public https addresses are called, checked right before every call.
 */
final class HttpBlock
{
    private const MAX_RESPONSE_BYTES = 262_144;

    public function __construct(private readonly UrlGuard $guard) {}

    /** @param array<string, mixed> $c */
    public function call(array $c, BotRuntime $rt, string $nodeId): bool
    {
        $url = Placeholders::fill($c['url'], $rt, secrets: true, escape: fn (string $v) => rawurlencode($v));
        if (! $this->sameHost((string) $c['url'], $url)) {
            return $this->fail($rt, $nodeId, 'blocked_url', 'placeholders may fill the path and query of the URL, not its address');
        }
        $headers = [];
        foreach ($c['headers'] as $h) {
            $headers[$h['key']] = Placeholders::fill($h['value'], $rt, secrets: true);
        }
        $json = $c['body'] !== null && (str_starts_with(ltrim($c['body']), '{') || str_starts_with(ltrim($c['body']), '['));
        $body = $c['body'] !== null
            ? Placeholders::fill($c['body'], $rt, secrets: true, escape: $json ? fn (string $v) => substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : null)
            : null;

        try {
            $this->guard->assertPublic($url);
        } catch (ValidationException $e) {
            return $this->fail($rt, $nodeId, 'blocked_url', (string) collect($e->errors())->flatten()->first());
        }

        $request = Http::timeout((int) $c['timeout'])->connectTimeout(min(5, (int) $c['timeout']))->withoutRedirecting()
            ->withHeaders(['Accept' => 'application/json', 'User-Agent' => '10X-Engage-Chatbot/1.0'] + $headers);
        if ((int) $c['retries'] > 0) {
            $request = $request->retry((int) $c['retries'], 300, fn (Throwable $e) => $e instanceof ConnectionException, throw: false);
        }
        if ($body !== null && $c['method'] !== 'GET') {
            $request = $request->withBody($body, $json ? 'application/json' : 'text/plain');
        }

        try {
            /** @var Response $response */
            $response = $request->send($c['method'], $url);
        } catch (Throwable $e) {
            $rt->state['vars']['http_status'] = 0;

            return $this->fail($rt, $nodeId, 'unreachable', $e instanceof ConnectionException ? 'No answer in time' : 'The request failed');
        }

        $status = $response->status();
        $rt->state['vars']['http_status'] = $status;
        $raw = mb_strcut($response->body(), 0, self::MAX_RESPONSE_BYTES);
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : ['body' => mb_substr($raw, 0, 1000)];

        foreach ($c['save'] as $map) {
            $value = data_get($data, $map['path']);
            $target = (string) FlowGraph::saveTarget($map['to']);
            if (str_starts_with($target, 'var:')) {
                $rt->state['vars'][substr($target, 4)] = is_scalar($value) || $value === null ? $value : json_decode((string) json_encode($value), true);
            } else {
                try {
                    if (str_starts_with($target, 'field:')) {
                        $rt->updateContact(['fields' => [substr($target, 6) => Placeholders::text($value)]]);
                    } elseif ($target === 'name' || $target === 'email') {
                        $rt->updateContact([$target => Placeholders::text($value)]);
                    }
                } catch (ValidationException) {
                    // A value that does not fit the field is skipped; the call itself still worked.
                }
            }
        }

        $ok = $response->successful();
        $rt->note("API call {$c['method']} ".parse_url($url, PHP_URL_HOST)." → {$status}".($ok ? '' : ' (Error branch)'));
        if (! $ok) {
            $rt->event($nodeId, 'error', "http_{$status}");
        }

        return $ok;
    }

    /**
     * Placeholders may fill the path and query, never the scheme or host: "{{var.x}}" in the host
     * would let a customer's answer pick which server we call.
     */
    private function sameHost(string $template, string $filled): bool
    {
        $host = parse_url((string) preg_replace('/\{\{[^}]*\}\}/', '', $template), PHP_URL_HOST);

        return is_string($host) && $host !== '' && $host === parse_url($filled, PHP_URL_HOST) && ! str_contains((string) preg_replace('#^https?://([^/?]*).*$#', '$1', $template), '{{');
    }

    private function fail(BotRuntime $rt, string $nodeId, string $reason, string $message): bool
    {
        $rt->event($nodeId, 'error', $reason);
        $rt->note("API call failed: {$message} (Error branch)");

        return false;
    }
}
