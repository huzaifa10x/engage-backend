<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;

/** Seats = active members + outstanding invitations (an invite reserves a seat). */
final class TeamSeatsCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::TeamSeats;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(function () use ($tenant): int {
            $members = TenantMembership::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('status', MembershipStatus::Active)
                ->count();

            $invites = Invitation::query()->where('tenant_id', $tenant->getKey())->pending()->count();

            return $members + $invites;
        });
    }
}
