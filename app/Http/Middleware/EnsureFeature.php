<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route-level entitlement gate: ->middleware('feature:api_access'). Limits are checked in actions. */
final class EnsureFeature
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::from($feature));

        return $next($request);
    }
}
