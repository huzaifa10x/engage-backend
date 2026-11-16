<?php

declare(strict_types=1);

namespace App\Infrastructure\Monitoring;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends unhandled errors to Sentry (handover 15 Nov, I.3) through Sentry's ingestion API (an event
 * envelope per error), configured with SENTRY_LARAVEL_DSN. Off when no DSN is set.
 *
 * Errors only, and only what's needed to fix them: exception type, message and stack trace, the
 * route, the request ID, the workspace ID and the user ID. Never request bodies, headers, cookies,
 * query strings, contact data or tokens. The same error is sent at most once a minute, and sending
 * never slows a request down for long (2-second timeout) or throws.
 */
final class SentryReporter
{
    private const CLIENT = 'engage-laravel/1.0';

    public function capture(Throwable $e): void
    {
        $dsn = self::parseDsn((string) config('engage.sentry.dsn'));
        if ($dsn === null || $this->sampledOut()) {
            return;
        }
        try {
            $fingerprint = sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());
            if (! Cache::add('sentry:sent:'.$fingerprint, 1, 60)) {
                return; // already reported in the last minute
            }
            $event = $this->event($e);
            $envelope = json_encode(['event_id' => $event['event_id'], 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'dsn' => (string) config('engage.sentry.dsn')])."\n"
                .json_encode(['type' => 'event', 'content_type' => 'application/json'])."\n"
                .json_encode($event, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

            Http::timeout(2)->connectTimeout(2)
                ->withHeaders([
                    'Content-Type' => 'application/x-sentry-envelope',
                    'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client='.self::CLIENT.', sentry_key='.$dsn['key'],
                ])
                ->withBody($envelope, 'application/x-sentry-envelope')
                ->post($dsn['endpoint']);
        } catch (Throwable) {
            // Monitoring must never break the app.
        }
    }

    /** @return ?array{key: string, endpoint: string} */
    public static function parseDsn(string $dsn): ?array
    {
        $p = parse_url(trim($dsn));
        if (! is_array($p) || ! isset($p['scheme'], $p['host'], $p['user'], $p['path']) || ! in_array($p['scheme'], ['https', 'http'], true)) {
            return null;
        }
        $path = trim($p['path'], '/');
        $project = basename($path);
        if ($project === '' || ! ctype_digit($project)) {
            return null;
        }
        $prefix = dirname($path) === '.' ? '' : '/'.dirname($path);
        $port = isset($p['port']) ? ':'.$p['port'] : '';

        return ['key' => $p['user'], 'endpoint' => "{$p['scheme']}://{$p['host']}{$port}{$prefix}/api/{$project}/envelope/"];
    }

    private function sampledOut(): bool
    {
        $rate = (float) config('engage.sentry.sample_rate', 1.0);

        return $rate < 1.0 && mt_rand() / mt_getrandmax() > $rate;
    }

    /** @return array<string, mixed> */
    private function event(Throwable $e): array
    {
        $context = app(TenantContext::class);
        // An HTTP request is one that matched a route; queue workers and commands have none.
        $request = app()->bound('request') && request()->route() !== null ? request() : null;
        $exceptions = [];
        for ($x = $e, $depth = 0; $x !== null && $depth < 5; $x = $x->getPrevious(), $depth++) {
            array_unshift($exceptions, [
                'type' => $x::class,
                'value' => mb_substr($x->getMessage(), 0, 1000),
                'stacktrace' => ['frames' => $this->frames($x)],
            ]);
        }

        return array_filter([
            'event_id' => str_replace('-', '', (string) Str::uuid()),
            'timestamp' => microtime(true),
            'platform' => 'php',
            'level' => 'error',
            'logger' => 'laravel',
            'environment' => (string) config('engage.sentry.environment', config('app.env')),
            'release' => config('engage.sentry.release') ?: null,
            'server_name' => gethostname() ?: null,
            'transaction' => $request?->route()?->getName() ?? ($request !== null ? $request->method().' '.$request->path() : ($_SERVER['argv'][1] ?? 'console')),
            'exception' => ['values' => $exceptions],
            'tags' => array_filter([
                'request_id' => Context::get('request_id'),
                'tenant_id' => $context->tenantOrNull()?->getKey(),
                'runtime' => $request !== null ? 'http' : 'console',
            ]),
            'user' => ($id = $context->userId()) !== null ? ['id' => $id] : null,
            // Path and method only: no query string, headers, cookies or body.
            'request' => $request !== null ? ['url' => $request->url(), 'method' => $request->method()] : null,
            'contexts' => ['runtime' => ['name' => 'php', 'version' => PHP_VERSION]],
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** @return list<array<string, mixed>> oldest call first, as Sentry expects */
    private function frames(Throwable $e): array
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $frames = [['filename' => str_replace($base, '', $e->getFile()), 'lineno' => $e->getLine(), 'in_app' => ! str_contains($e->getFile(), '/vendor/')]];
        foreach (array_slice($e->getTrace(), 0, 60) as $t) {
            $file = (string) ($t['file'] ?? '[internal]');
            $frames[] = [
                'filename' => str_replace($base, '', $file),
                'lineno' => $t['line'] ?? null,
                'function' => isset($t['class']) ? $t['class'].($t['type'] ?? '::').$t['function'] : $t['function'],
                'in_app' => $file !== '[internal]' && ! str_contains($file, '/vendor/'),
            ];
        }

        return array_reverse($frames);
    }
}
