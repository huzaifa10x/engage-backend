<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Exceptions\InvitationInvalid;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Sign-up from an invitation link: only a name, a password and the terms. No workspace is created —
 * the person joins the inviting workspace with the invited role. The email address is the invited
 * one (the link was emailed there, so it counts as verified). Someone who already has an account
 * signs in and accepts instead.
 */
final class RegisterInvitee
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcceptInvitation $accept,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, password: string, timezone?: ?string}  $input
     * @return array{user: User, membership: TenantMembership}
     */
    public function __invoke(string $token, array $input): array
    {
        return DB::transaction(function () use ($token, $input) {
            $invitation = $this->context->bypass(fn () => Invitation::query()->where('token_hash', Invitation::hashToken($token))->lockForUpdate()->first());
            if (! $invitation instanceof Invitation) {
                throw InvitationInvalid::because('not_found');
            }
            match (true) {
                $invitation->accepted_at !== null => throw InvitationInvalid::because('accepted'),
                $invitation->revoked_at !== null => throw InvitationInvalid::because('revoked'),
                ! $invitation->expires_at->isFuture() => throw InvitationInvalid::because('expired'),
                default => null,
            };
            $email = mb_strtolower(trim($invitation->email));
            $taken = $this->context->bypass(fn () => User::query()->whereRaw('lower(email) = ?', [$email])->exists());
            if ($taken) {
                throw InvitationInvalid::because('account_exists');
            }

            $user = $this->context->bypass(function () use ($input, $email) {
                $user = User::query()->create([
                    'name' => trim(preg_replace('/\s+/u', ' ', $input['name']) ?? $input['name']),
                    'email' => $email,
                    'password' => $input['password'],
                    'timezone' => $input['timezone'] ?? null,
                ]);
                $user->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now()])->save();

                return $user;
            });

            $membership = ($this->accept)($user, $token);
            $tenant = $this->context->bypass(fn () => Tenant::query()->findOrFail($invitation->tenant_id));
            $this->context->run($tenant, fn () => $this->audit->record('user.registered_from_invitation', $invitation, meta: ['user_id' => $user->getKey()]), $membership);

            return ['user' => $user, 'membership' => $membership];
        });
    }
}
