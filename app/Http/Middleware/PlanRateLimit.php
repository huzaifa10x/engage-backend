<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API rate limit: every API key of a workspace shares the requests-per-minute allowance of its
 * plan (Super Admin → Plans, or a per-company override). The portal has its own, separate per-user
 * abuse limit, so a busy integration can never slow the agents down. Also counts the requests of
 * this minute and today for the Developer page (usage()).
 */
final class PlanRateLimit
{
    public function __construct(private readonly TenantContext $context, private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenantOrNull();
        if ($tenant === null) {
            return $next($request);
        }

        self::count($tenant->id);
        $entitlement = $this->entitlements->for($tenant)->get(FeatureKey::ApiRateLimitPerMinute);
        if ($entitlement->isUnlimited() || ! $entitlement->enabled || (int) $entitlement->limit <= 0) {
            return $next($request); // unlimited, or not configured for this plan
        }

        $perMinute = (int) $entitlement->limit;
        $key = self::key($tenant->id);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $retry = RateLimiter::availableIn($key);

            return response()->json(['error' => [
                'code' => 'rate_limited',
                'message' => "Your plan allows {$perMinute} requests per minute. Try again in {$retry} seconds, or upgrade your plan for a higher limit.",
            ]], 429, ['Retry-After' => (string) $retry, 'X-RateLimit-Limit' => (string) $perMinute, 'X-RateLimit-Remaining' => '0']);
        }
        RateLimiter::hit($key, 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $perMinute);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $perMinute)));

        return $response;
    }

    public static function key(string $tenantId): string
    {
        return 'plan-api:'.$tenantId;
    }

    /**
     * Requests this minute (the limiter's window) and today (UTC day), for the Developer page.
     *
     * @return array{this_minute: int, today: int}
     */
    public static function usage(string $tenantId): array
    {
        return [
            'this_minute' => RateLimiter::attempts(self::key($tenantId)),
            'today' => (int) Cache::get(self::dayKey($tenantId), 0),
        ];
    }

    private static function count(string $tenantId): void
    {
        $key = self::dayKey($tenantId);
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }

    private static function dayKey(string $tenantId): string
    {
        return 'plan-api-day:'.$tenantId.':'.now()->utc()->toDateString();
    }
}
