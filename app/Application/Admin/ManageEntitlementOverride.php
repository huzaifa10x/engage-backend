<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Feature;
use App\Domain\Plans\Models\TenantEntitlementOverride;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Per-company adjustments (enterprise contracts, add-ons, design-partner perks). Tenants can
 * read overrides but only the platform can write them (RLS WITH CHECK engage_rls_bypass()).
 */
final class ManageEntitlementOverride
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{enabled: ?bool, limit: ?int, unlimited: bool, expires_at: ?CarbonInterface, reason: string} $data */
    public function set(Tenant $tenant, FeatureKey $key, array $data, PlatformAdmin $admin): TenantEntitlementOverride
    {
        $override = $this->context->bypass(fn () => DB::transaction(function () use ($tenant, $key, $data, $admin) {
            $feature = Feature::query()->where('key', $key->value)->firstOrFail();

            $existing = TenantEntitlementOverride::query()
                ->where('tenant_id', $tenant->getKey())->where('feature_id', $feature->getKey())->first();
            $before = $existing?->only(['enabled', 'limit_value', 'unlimited', 'expires_at']);

            $override = TenantEntitlementOverride::query()->updateOrCreate(
                ['tenant_id' => $tenant->getKey(), 'feature_id' => $feature->getKey()],
                [
                    'enabled' => $data['enabled'],
                    'limit_value' => $key->type()->isQuantified() && ! $data['unlimited'] ? $data['limit'] : null,
                    'unlimited' => $key->type()->isQuantified() && $data['unlimited'],
                    'reason' => $data['reason'],
                    'expires_at' => $data['expires_at'],
                    'created_by_admin_id' => $admin->getKey(),
                ],
            );

            $this->audit->record('entitlement.override_set', $tenant, before: $before ?? [],
                after: ['feature' => $key->value] + $override->only(['enabled', 'limit_value', 'unlimited', 'expires_at']),
                meta: ['reason' => $data['reason']], tenantId: $tenant->getKey());

            return $override;
        }));

        $this->entitlements->forget($tenant);

        return $override;
    }

    public function remove(Tenant $tenant, FeatureKey $key, string $reason): void
    {
        $this->context->bypass(fn () => DB::transaction(function () use ($tenant, $key, $reason) {
            $override = TenantEntitlementOverride::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereHas('feature', fn ($q) => $q->where('key', $key->value))
                ->first();

            if ($override === null) {
                return;
            }

            $this->audit->record('entitlement.override_removed', $tenant,
                before: ['feature' => $key->value] + $override->only(['enabled', 'limit_value', 'unlimited']),
                meta: ['reason' => $reason], tenantId: $tenant->getKey());

            $override->delete();
        }));

        $this->entitlements->forget($tenant);
    }
}
