<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Exceptions\InvitationInvalid;
use App\Domain\Tenancy\Exceptions\TenantUnavailable;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class AcceptInvitation
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, string $token): TenantMembership
    {
        return DB::transaction(function () use ($user, $token) {
            // The tenant is unknown until the token is resolved — platform lookup by hash only,
            // row-locked so a token cannot be accepted twice concurrently.
            [$invitation, $tenant] = $this->context->bypass(function () use ($token) {
                $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->lockForUpdate()->first();

                return [$invitation, $invitation !== null ? Tenant::query()->find($invitation->tenant_id) : null];
            });

            if (! $invitation instanceof Invitation || ! $tenant instanceof Tenant || ! $invitation->isPending()) {
                throw new InvitationInvalid;
            }

            if (! hash_equals($invitation->email, $user->email)) {
                throw new InvitationInvalid('This invitation was sent to a different email address.');
            }

            if (! $tenant->status->isUsable()) {
                throw new TenantUnavailable($tenant->status);
            }

            return $this->context->run($tenant, function () use ($invitation, $user) {
                /** @var TenantMembership $membership */
                $membership = TenantMembership::query()->updateOrCreate(
                    ['user_id' => $user->getKey()],
                    [
                        'role_id' => $invitation->role_id,
                        'status' => MembershipStatus::Active,
                        'invited_by_user_id' => $invitation->getAttribute('invited_by_user_id'),
                        'joined_at' => now(),
                    ],
                );

                $invitation->forceFill(['accepted_at' => now()])->save();
                $user->forceFill(['last_active_tenant_id' => $invitation->tenant_id])->save();

                $this->audit->record('invitation.accepted', $invitation, meta: ['membership_id' => $membership->getKey()]);

                return $membership;
            });
        });
    }
}
