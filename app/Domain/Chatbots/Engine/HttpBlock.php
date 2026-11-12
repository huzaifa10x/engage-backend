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
        // Secrets are only filled in for a call that is really made. A test makes real calls only to
        // the workspace's test endpoints (checked on the address without secrets).
        $live = $rt->liveCalls() && $rt->mayCallLive(Placeholders::fill($c['url'], $rt, escape: fn (string $v) => rawurlencode($v)));
        $url = Placeholders::fill($c['url'], $rt, secrets: $live, escape: fn (string $v) => rawurlencode($v));
        if (! $this->sameHost((string) $c['url'], $url)) {
            return $this->fail($rt, $nodeId, 'blocked_url', 'placeholders may fill the path and query of the URL, not its address');
        }
        $headers = [];
        foreach ($c['headers'] as $h) {
            $headers[$h['key']] = Placeholders::fill($h['value'], $rt, secrets: $live);
        }
        $json = $c['body'] !== null && (str_starts_with(ltrim($c['body']), '{') || str_starts_with(ltrim($c['body']), '['));
        $body = $c['body'] !== null
            ? Placeholders::fill($c['body'], $rt, secrets: $live, escape: $json ? fn (string $v) => substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : null)
            : null;

        if (! $live) {
            return $this->dryRun($c, $rt, $nodeId, $url, $body);
        }

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
            $rt->liveCallMade(['kind' => 'api', 'method' => $c['method'], 'host' => parse_url($url, PHP_URL_HOST), 'path' => parse_url($url, PHP_URL_PATH), 'node' => $nodeId, 'status' => 0]);

            return $this->fail($rt, $nodeId, 'unreachable', $e instanceof ConnectionException ? 'No answer in time' : 'The request failed');
        }

        $status = $response->status();
        $rt->state['vars']['http_status'] = $status;
        $rt->liveCallMade(['kind' => 'api', 'method' => $c['method'], 'host' => parse_url($url, PHP_URL_HOST), 'path' => parse_url($url, PHP_URL_PATH), 'node' => $nodeId, 'status' => $status]);
        $raw = mb_strcut($response->body(), 0, self::MAX_RESPONSE_BYTES);
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : ['body' => mb_substr($raw, 0, 1000)];

        $this->saveResponse($c, $rt, $data);

        $ok = $response->successful();
        $rt->note("API call {$c['method']} ".parse_url($url, PHP_URL_HOST)." → {$status}".($ok ? '' : ' (Error branch)'));
        if (! $ok) {
            $rt->event($nodeId, 'error', "http_{$status}");
        }

        return $ok;
    }

    /**
     * Test chat: nothing is sent. The step answers with its sample response (sample_status /
     * sample_body), or fails as chosen in the test settings, and the transcript shows what would
     * have been sent (secrets left out).
     *
     * @param  array<string, mixed>  $c
     */
    private function dryRun(array $c, BotRuntime $rt, string $nodeId, string $url, ?string $body): bool
    {
        $target = $c['method'].' '.parse_url($url, PHP_URL_HOST).(parse_url($url, PHP_URL_PATH) ?? '');
        $rt->note('(Test) Not sent: would call '.$target.($body !== null && $c['method'] !== 'GET' ? ' with '.mb_substr($body, 0, 300) : '').'.');
        $mode = $rt->apiMode();
        if ($mode === 'timeout') {
            $rt->state['vars']['http_status'] = 0;

            return $this->fail($rt, $nodeId, 'unreachable', 'No answer in time (test setting)');
        }
        $status = $mode === 'error' ? (($c['sample_status'] ?? 0) >= 400 ? (int) $c['sample_status'] : 500) : (int) ($c['sample_status'] ?? 200);
        $rt->state['vars']['http_status'] = $status;
        // A failed call answers with an error body, as a real one would: the success sample is not mapped.
        $data = $mode === 'error' ? ['error' => 'Test failure'] : (($c['sample_body'] ?? null) !== null ? json_decode((string) $c['sample_body'], true) : null);
        $this->saveResponse($c, $rt, is_array($data) ? $data : []);
        $ok = $status >= 200 && $status < 300;
        $rt->note('(Test) Sample answer '.$status.($ok ? '' : ' (Error branch)'));
        if (! $ok) {
            $rt->event($nodeId, 'error', "http_{$status}");
        }

        return $ok;
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<mixed>  $data
     */
    private function saveResponse(array $c, BotRuntime $rt, array $data): void
    {
        foreach ($c['save'] as $map) {
            $value = data_get($data, $map['path']);
            $target = (string) NodeConfig::saveTarget($map['to']);
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
    }

    /**
     * Google Sheets row: posts {sheet, row: {column: value}} as JSON to the sheet's Apps Script web
     * app, which appends the row. Google answers a web-app POST with a redirect after running the
     * script, so 2xx and 3xx both count as written.
     *
     * @param  array<string, mixed>  $c
     */
    public function sheet(array $c, BotRuntime $rt, string $nodeId): bool
    {
        $url = (string) $c['webapp_url'];
        if (! str_starts_with($url, 'https://script.google.com/')) {
            return $this->fail($rt, $nodeId, 'blocked_url', 'the sheet link must be a Google Apps Script web app (https://script.google.com/…)');
        }
        $row = [];
        foreach ($c['columns'] as $col) {
            $row[$col['name']] = Placeholders::fill($col['value'], $rt);
        }
        if (! $rt->liveCalls() || ! $rt->mayCallLive($url)) {
            $rt->note('(Test) Not sent: would add a row to “'.$c['sheet'].'”: '.implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($row), $row)));

            return match ($rt->apiMode()) {
                'timeout' => $this->fail($rt, $nodeId, 'unreachable', 'Google Sheets did not answer in time (test setting)'),
                'error' => $this->fail($rt, $nodeId, 'http_500', 'Google Sheets answered 500 (test setting)'),
                default => true,
            };
        }
        try {
            $this->guard->assertPublic($url);
            $response = Http::timeout(8)->connectTimeout(5)->withoutRedirecting()->acceptJson()
                ->post($url, ['sheet' => $c['sheet'], 'row' => $row, 'submitted_at' => now()->toIso8601String()]);
        } catch (Throwable $e) {
            $rt->liveCallMade(['kind' => 'sheets', 'method' => 'POST', 'host' => parse_url($url, PHP_URL_HOST), 'path' => parse_url($url, PHP_URL_PATH), 'node' => $nodeId, 'status' => 0]);

            return $this->fail($rt, $nodeId, 'unreachable', $e instanceof ValidationException ? 'the sheet link is not allowed' : 'Google Sheets did not answer in time');
        }
        $rt->liveCallMade(['kind' => 'sheets', 'method' => 'POST', 'host' => parse_url($url, PHP_URL_HOST), 'path' => parse_url($url, PHP_URL_PATH), 'node' => $nodeId, 'status' => $response->status()]);
        $ok = $response->status() >= 200 && $response->status() < 400;
        if (! $ok) {
            return $this->fail($rt, $nodeId, 'http_'.$response->status(), 'Google Sheets answered '.$response->status());
        }
        $rt->note('Row added to Google Sheets: '.implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($row), $row)));

        return true;
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
        $rt->note("Request failed: {$message}");

        return false;
    }
}
