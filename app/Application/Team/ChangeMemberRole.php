<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Access\Models\Role;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // The account given to Meta App Review must keep working: only a Super Admin can end it.
        if ($member->user()->first()?->isReviewAccount() === true) {
            throw ValidationException::withMessages(['member' => 'This is the review account given to Meta. It cannot be given another role; ask 10X Engage support to end the review account first.']);
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
