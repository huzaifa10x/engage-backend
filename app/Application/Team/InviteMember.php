<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Access\Models\Role;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Exceptions\MembershipConflict;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class InviteMember
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{invitation: Invitation, token: string} */
    public function __invoke(string $email, Role $role): array
    {
        $tenant = $this->context->tenant();
        $actor = $this->context->membership() ?? throw new AuthorizationException;
        $email = mb_strtolower(trim($email));

        if ($role->isOwnerRole() && ! $actor->isOwner()) {
            throw new AuthorizationException('Only owners can invite owners.');
        }

        $existingUserId = User::query()->where('email', $email)->value('id');
        if ($existingUserId !== null && TenantMembership::query()->where('user_id', $existingUserId)->exists()) {
            throw new MembershipConflict('This person is already a member of the workspace.');
        }

        // Expired-but-unaccepted invitations would block the partial unique index.
        Invitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '<=', now())->update(['revoked_at' => now()]);

        if (Invitation::query()->where('email', $email)->pending()->exists()) {
            throw new MembershipConflict('An invitation is already pending for this email.');
        }

        $token = Str::random(64);

        $invitation = $this->entitlements->withinLimit($tenant, FeatureKey::TeamSeats, 1, fn () => DB::transaction(function () use ($email, $role, $actor, $token) {
            $invitation = Invitation::query()->create([
                'email' => $email,
                'role_id' => $role->getKey(),
                'token_hash' => Invitation::hashToken($token),
                'invited_by_user_id' => $actor->user_id,
                'expires_at' => now()->addHours((int) config('engage.invitations.ttl_hours', 168)),
            ]);

            $this->audit->record('invitation.created', $invitation, after: ['email' => $email, 'role' => $role->key]);

            return $invitation;
        }));

        Notification::route('mail', $email)->notify(new TenantInvitationNotification($tenant->name, $token, $role->name));

        return ['invitation' => $invitation, 'token' => $token];
    }
}
