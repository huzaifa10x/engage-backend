<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Access\Models\Role;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ChangeMemberRole
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly OwnerGuard $owners,
    ) {}

    public function __invoke(TenantMembership $member, Role $role): TenantMembership
    {
        $actor = $this->context->membership() ?? throw new AuthorizationException;

        if (($member->isOwner() || $role->isOwnerRole()) && ! $actor->isOwner()) {
            throw new AuthorizationException('Only owners can grant or revoke the owner role.');
        }

        return DB::transaction(function () use ($member, $role) {
            $before = $member->role?->key;

            if ($member->isOwner() && ! $role->isOwnerRole()) {
                $this->owners->ensureAnotherOwnerExists($member);
            }

            $member->forceFill(['role_id' => $role->getKey()])->save();
            $member->setRelation('role', $role);

            $this->audit->record('membership.role_changed', $member, before: ['role' => $before], after: ['role' => $role->key]);

            return $member;
        });
    }
}
