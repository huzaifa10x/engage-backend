<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan-wise API rate limit: all members of a workspace share the requests-per-minute allowance
 * of its plan (Super Admin → Plans, or a per-company override). Must run AFTER the tenant has
 * been resolved, which is why it is its own middleware rather than part of `throttle:api`.
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

        $entitlement = $this->entitlements->for($tenant)->get(FeatureKey::ApiRateLimitPerMinute);
        if ($entitlement->isUnlimited() || ! $entitlement->enabled || (int) $entitlement->limit <= 0) {
            return $next($request); // unlimited, or not configured for this plan: the global limiter still applies
        }

        $perMinute = (int) $entitlement->limit;
        $key = 'plan-api:'.$tenant->id;

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
}
