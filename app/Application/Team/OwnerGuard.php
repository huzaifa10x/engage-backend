<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Exceptions\LastOwner;
use App\Domain\Tenancy\Models\TenantMembership;

final class OwnerGuard
{
    /**
     * Locks ALL active owner rows of the tenant (Postgres forbids FOR UPDATE with aggregates),
     * so two concurrent demotions/removals serialize and cannot remove the last owner.
     */
    public function ensureAnotherOwnerExists(TenantMembership $leaving): void
    {
        $ownerIds = TenantMembership::query()
            ->where('role_id', Role::system(SystemRole::Owner)->getKey())
            ->where('status', MembershipStatus::Active)
            ->lockForUpdate()
            ->pluck('id');

        if ($ownerIds->reject(fn ($id) => $id === $leaving->getKey())->isEmpty()) {
            throw new LastOwner;
        }
    }
}
