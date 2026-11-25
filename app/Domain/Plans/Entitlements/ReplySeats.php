<?php

declare(strict_types=1);

namespace App\Domain\Plans\Entitlements;

use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;

/**
 * Batch 6: every seat beyond the plan's is paid. Members above the limit (after a downgrade, on
 * Free, or while a renewal is unpaid) stay in the workspace and can read, but cannot reply until
 * the plan has enough seats again.
 *
 * Who keeps a seat: owners first, then the longest-standing members, up to the plan's seat count.
 */
final class ReplySeats
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly TenantContext $context) {}

    public function canReply(Tenant $tenant, TenantMembership $member): bool
    {
        if ($member->isOwner()) {
            return true;
        }
        $seats = $this->limit($tenant);

        return $seats === null || in_array($member->getKey(), $this->seated($tenant, $seats), true);
    }

    /** The plan's seat count, or null when unlimited. */
    public function limit(Tenant $tenant): ?int
    {
        $entitlement = $this->entitlements->for($tenant)->get(FeatureKey::TeamSeats);

        return $entitlement->enabled ? $entitlement->limit : 1;
    }

    /** @return list<string> ids of the members who hold a seat */
    private function seated(Tenant $tenant, int $seats): array
    {
        $members = $this->context->bypass(fn () => TenantMembership::query()->where('tenant_id', $tenant->getKey())
            ->active()->with('role')->orderBy('created_at')->orderBy('id')->get());

        return $members->sortBy(fn (TenantMembership $m) => $m->isOwner() ? 0 : 1, SORT_NUMERIC)
            ->take(max(0, $seats))->map(fn (TenantMembership $m) => (string) $m->getKey())->values()->all();
    }
}
