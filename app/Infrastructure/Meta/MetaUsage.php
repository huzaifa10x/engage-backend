<?php

declare(strict_types=1);

namespace App\Infrastructure\Meta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Our own call budget at Meta. Every Graph response carries how much of the rate limit we have used:
 *
 *   X-App-Usage                  {"call_count": 12, "total_time": 3, "total_cputime": 2}            (whole app)
 *   X-Business-Use-Case-Usage    {"<business or WABA id>": [{"type": "whatsapp_business_management",
 *                                  "call_count": 80, "total_time": 10, "total_cputime": 7,
 *                                  "estimated_time_to_regain_access": 0}]}                          (per business)
 *
 * Each figure is a percentage of the limit. We keep the highest per ID (and for the app) for a few
 * minutes. From 75 % on, background work (access checks, template syncs, limit refreshes) waits;
 * customer messages keep going. When Meta names a time to regain access, nothing is called for that
 * ID until then.
 */
final class MetaUsage
{
    public const SLOW_DOWN_AT = 75;

    private const TTL = 600;

    /** Meta's "too many calls" errors (app, user, page, business use case, WABA). */
    public const RATE_LIMIT_CODES = [4, 17, 32, 613, 80004, 80007, 80008, 80014];

    public static function record(Response $response): void
    {
        try {
            $app = self::decode($response->header('X-App-Usage'));
            if ($app !== null) {
                self::store('app', self::max($app), 0);
            }
            $buc = self::decode($response->header('X-Business-Use-Case-Usage'));
            foreach ((array) $buc as $id => $entries) {
                $pct = 0;
                $regain = 0;
                foreach ((array) $entries as $entry) {
                    if (is_array($entry)) {
                        $pct = max($pct, self::max($entry));
                        $regain = max($regain, (int) ($entry['estimated_time_to_regain_access'] ?? 0));
                    }
                }
                self::store((string) $id, $pct, $regain);
            }
        } catch (\Throwable $e) {
            Log::debug('Meta usage headers could not be read', ['error' => $e->getMessage()]);
        }
    }

    /** Meta refused a call for being too frequent: hold this ID (and the app if it is app-wide) for a while. */
    public static function limited(?string $id, MetaApiException $e): void
    {
        if (! in_array($e->metaCode, self::RATE_LIMIT_CODES, true)) {
            return;
        }
        $minutes = max(1, (int) ($e->error['error_data']['estimated_time_to_regain_access'] ?? 5));
        self::store(in_array($e->metaCode, [4, 17], true) || $id === null ? 'app' : $id, 100, $minutes);
    }

    /** The highest usage we know for this ID (business, WABA) or for the app, in percent. */
    public static function percent(?string $id = null): int
    {
        $app = (array) Cache::get('meta:usage:app', []);
        $own = $id !== null ? (array) Cache::get('meta:usage:'.$id, []) : [];

        return max((int) ($app['pct'] ?? 0), (int) ($own['pct'] ?? 0));
    }

    /** Seconds until Meta lets us call for this ID again (0 = now); with $app, an app-wide hold counts too. */
    public static function blockedFor(?string $id = null, bool $app = true): int
    {
        $until = 0;
        foreach (array_filter([$app ? 'app' : null, $id]) as $key) {
            $until = max($until, (int) (((array) Cache::get('meta:usage:'.$key, []))['until'] ?? 0));
        }

        return max(0, $until - time());
    }

    /** Background work for this ID should wait: usage at 75 % or more, or Meta asked us to stop. */
    public static function shouldDefer(?string $id = null): bool
    {
        return self::blockedFor($id) > 0 || self::percent($id) >= self::SLOW_DOWN_AT;
    }

    /** @return array<string, mixed>|null */
    private static function decode(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        $data = json_decode($header, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $entry */
    private static function max(array $entry): int
    {
        return (int) max((float) ($entry['call_count'] ?? 0), (float) ($entry['total_time'] ?? 0), (float) ($entry['total_cputime'] ?? 0));
    }

    private static function store(string $id, int $pct, int $regainMinutes): void
    {
        $key = 'meta:usage:'.$id;
        $current = (array) Cache::get($key, []);
        $until = $regainMinutes > 0 ? time() + $regainMinutes * 60 : (int) ($current['until'] ?? 0);
        Cache::put($key, ['pct' => $pct, 'until' => $until, 'at' => time()], self::TTL + max(0, $until - time()));
        if ($pct >= self::SLOW_DOWN_AT && (int) ($current['pct'] ?? 0) < self::SLOW_DOWN_AT) {
            Log::warning('Meta API usage passed 75 %; background calls slow down', ['id' => $id, 'percent' => $pct, 'regain_minutes' => $regainMinutes]);
        }
    }
}
