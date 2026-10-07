<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Developer\Models\ApiKey;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API authentication: "Authorization: Bearer eng_live_…".
 *
 * The key decides the workspace; nothing in the request can choose another one. A key works only
 * while it is not revoked or expired, the workspace is active, and the plan includes API access.
 * A required scope can be named per route: ->middleware('api.key:messages:send').
 */
final class AuthenticateApiKey
{
    public function __construct(private readonly TenantContext $context, private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = (string) $request->bearerToken();
        if ($token === '' || ! str_starts_with($token, 'eng_')) {
            return $this->deny(401, 'missing_api_key', 'Send your API key in the Authorization header: "Bearer eng_live_…".');
        }

        /** @var ?ApiKey $key */
        $key = $this->context->bypass(fn () => ApiKey::query()->where('key_hash', ApiKey::hash($token))->first());
        if ($key === null || ! $key->isUsable()) {
            return $this->deny(401, 'invalid_api_key', 'This API key is not valid. It may have been revoked or have expired.');
        }

        $tenant = $this->context->bypass(fn () => Tenant::query()->find($key->tenant_id));
        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            return $this->deny(403, 'workspace_unavailable', 'This workspace is not active.');
        }
        if (! $this->entitlements->for($tenant)->allows(FeatureKey::ApiAccess)) {
            return $this->deny(403, 'feature_not_available', 'API access is not included in this workspace\'s plan.');
        }
        if ($scope !== null && ! $key->allows($scope)) {
            return $this->deny(403, 'insufficient_scope', "This API key does not have the \"{$scope}\" permission.");
        }

        $this->context->set($tenant);
        $request->attributes->set('api_key', $key);

        // "Last used" is for the portal; once a minute per key is plenty.
        if (Cache::add('api-key-seen:'.$key->id, 1, 60)) {
            $this->context->bypass(fn () => ApiKey::query()->whereKey($key->id)->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]));
        }

        return $next($request);
    }

    private function deny(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
