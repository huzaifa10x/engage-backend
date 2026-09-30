<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RemoveMember
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly OwnerGuard $owners,
    ) {}

    public function __invoke(TenantMembership $member): void
    {
        $actor = $this->context->membership() ?? throw new AuthorizationException;

        if ($member->isOwner() && ! $actor->isOwner()) {
            throw new AuthorizationException('Only owners can remove an owner.');
        }

        DB::transaction(function () use ($member) {
            if ($member->isOwner()) {
                $this->owners->ensureAnotherOwnerExists($member);
            }

            $this->audit->record('membership.removed', $member, before: [
                'user_id' => $member->user_id,
                'role' => $member->role?->key,
            ]);

            $member->delete();
        });
    }
}
