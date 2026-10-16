<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The one place that decides how fast a number may send, and that paces every send to it.
 *
 * LIMIT (messages per second, per connected number) = the lowest of
 *   • the workspace plan's "messages per second"  (Starter 20, Growth 30, Pro 35 …),
 *   • what Meta allows this number                (phone_numbers.max_mps),
 *   • the platform ceiling, hard-coded below: 35 for a Cloud API number, 15 for a number shared
 *     with the WhatsApp Business app (coexistence). Meta allows 80 and 20; we stay well under.
 * The ceilings are applied last and unconditionally: no plan, upgrade, override or "unlimited"
 * entitlement can lift them. Anything missing or unreadable falls back to the lowest value,
 * never to a higher one. Raising a ceiling is a code change on purpose.
 *
 * PACING: sends are spaced evenly with no burst allowance, exactly at the limit (35 → one send
 * every 28.6 ms), so there are never more than `limit` messages in ANY one-second window — not
 * just on average. The spacing is decided atomically in Redis/Valkey, shared by every worker and
 * server.
 *
 * Nothing here is reachable from a browser: the limit is computed from the database when the
 * message is actually sent, and every message goes through SendWhatsappMessage, which calls this.
 */
final class SendThroughput
{
    /** Platform ceiling for a Cloud API number (Meta's default is 80 per second). */
    public const STANDARD_MAX_MPS = 35;

    /** Platform ceiling for a coexistence number (Meta fixes these at 20 per second). */
    public const COEXISTENCE_MAX_MPS = 15;

    /** Reserve the next slot, or refuse when the queue for this number is already this far ahead. */
    private const LUA = <<<'LUA'
local t = redis.call('TIME')
local now = t[1] * 1000 + t[2] / 1000
local tat = tonumber(redis.call('GET', KEYS[1]) or '0')
local slot = math.max(now, tat)
local wait = slot - now
if wait > tonumber(ARGV[2]) then
    return {0, math.ceil(wait)}
end
redis.call('SET', KEYS[1], string.format('%.3f', slot + tonumber(ARGV[1])), 'PX', 120000)
return {1, math.ceil(wait)}
LUA;

    public function __construct(private readonly EntitlementService $entitlements, private readonly TenantContext $context) {}

    /** Messages per second this number may send right now. Always between 1 and Meta's own cap. */
    public function limitFor(PhoneNumber $number): int
    {
        $ceiling = $number->isCoexistence() ? self::COEXISTENCE_MAX_MPS : self::STANDARD_MAX_MPS;
        $fallback = min($ceiling, max(1, (int) config('engage.meta.fallback_max_mps', 20)));

        // What Meta allows the number itself.
        $numberCap = (int) $number->max_mps > 0 ? (int) $number->max_mps : $fallback;

        // What the workspace's plan allows.
        $planCap = $fallback;
        try {
            $tenant = $this->context->bypass(fn () => Tenant::query()->find($number->tenant_id));
            if ($tenant !== null) {
                $entitlement = $this->entitlements->for($tenant)->get(FeatureKey::MessagesPerSecond);
                if ($entitlement->enabled && $entitlement->isUnlimited()) {
                    $planCap = $numberCap;
                } elseif ($entitlement->enabled && (int) $entitlement->limit > 0) {
                    $planCap = (int) $entitlement->limit;
                }
            }
        } catch (Throwable) {
            $planCap = $fallback; // unreadable plan → the safe, low value
        }

        // The platform ceiling always wins, last, regardless of everything above.
        return max(1, min($planCap, $numberCap, $ceiling));
    }

    /**
     * Claims the next send slot for this number.
     *
     * @return int|null milliseconds to wait before sending (0 = now); null = too far ahead, try again later
     */
    public function reserve(PhoneNumber $number, int $maxWaitMs = 1500): ?int
    {
        // Paced exactly at the limit: the ceilings already sit well below Meta's own caps.
        $intervalMs = 1000 / $this->limitFor($number);
        $key = 'wa-pace:'.$number->id;

        if (config('cache.default') === 'redis') {
            /** @var array{0: int, 1: int} $result */
            // Laravel's connection takes (script, number of keys, ...arguments) for both phpredis and predis.
            $result = Redis::connection()->eval(self::LUA, 1, $key, (string) $intervalMs, (string) $maxWaitMs); // @phpstan-ignore arguments.count, argument.type, argument.type

            return (int) $result[0] === 1 ? (int) $result[1] : null;
        }

        // Same algorithm for single-process setups (local development, tests).
        return Cache::lock($key.':lock', 5)->block(5, function () use ($key, $intervalMs, $maxWaitMs): ?int {
            $now = microtime(true) * 1000;
            $slot = max($now, (float) Cache::get($key, 0));
            if ($slot - $now > $maxWaitMs) {
                return null;
            }
            Cache::put($key, $slot + $intervalMs, 120);

            return (int) ceil($slot - $now);
        });
    }

    /** How long to stay away when reserve() refused (seconds, for a queue release). */
    public function retryAfterSeconds(): int
    {
        return 2;
    }
}
