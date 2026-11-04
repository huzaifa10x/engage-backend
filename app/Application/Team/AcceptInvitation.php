<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Exceptions\InvitationInvalid;
use App\Domain\Tenancy\Exceptions\TenantUnavailable;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Accept a workspace invitation. Checks, in order: the token exists (by hash), it was not revoked,
 * not already used, not expired, and the signed-in account's email is the invited one. Each failure
 * says which check failed (details.reason) so the page can tell the person what to do.
 * Accepting twice by the same person (double click, page refresh) returns the same membership.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly NumberAccess $numbers,
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

            if (! $invitation instanceof Invitation || ! $tenant instanceof Tenant) {
                throw InvitationInvalid::because('not_found');
            }

            $sameEmail = InvitationStatus::sameEmail($invitation->email, (string) $user->email);

            if ($invitation->accepted_at !== null) {
                // Already accepted by this person: the same outcome again, not an error.
                $existing = $sameEmail ? $this->context->run($tenant, fn () => TenantMembership::query()
                    ->where('user_id', $user->getKey())->where('status', MembershipStatus::Active)->first()) : null;
                if ($existing instanceof TenantMembership) {
                    return $existing;
                }

                throw InvitationInvalid::because('accepted');
            }
            if ($invitation->revoked_at !== null) {
                throw InvitationInvalid::because('revoked');
            }
            if (! $invitation->expires_at->isFuture()) {
                throw InvitationInvalid::because('expired');
            }
            if (! $sameEmail) {
                throw InvitationInvalid::because('email_mismatch', ['invited_email' => InvitationStatus::maskEmail($invitation->email)]);
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

                // The numbers chosen when inviting (roles with every number need none). Numbers removed
                // from the workspace since the invitation was sent are skipped.
                $ids = (array) ($invitation->phone_number_ids ?? []);
                if ($ids !== [] && ! $this->numbers->hasAllNumbers($membership->load('role'))) {
                    $this->numbers->setGrants($membership, PhoneNumber::query()->whereIn('id', $ids)->pluck('id')->all());
                }

                $invitation->forceFill(['accepted_at' => now()])->save();
                $user->forceFill(['last_active_tenant_id' => $invitation->tenant_id])->save();

                $this->audit->record('invitation.accepted', $invitation, meta: ['membership_id' => $membership->getKey()]);

                return $membership;
            });
        });
    }
}
