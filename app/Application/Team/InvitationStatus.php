<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/**
 * What the invitation page shows before accepting: the workspace, the role, the invited address
 * (masked), and whether the link can still be used — and by the account that is signed in.
 * The token is the only key; nothing is returned for an unknown token.
 */
final class InvitationStatus
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed>|null */
    public function __invoke(string $token, ?User $viewer): ?array
    {
        return $this->context->bypass(function () use ($token, $viewer) {
            $invitation = Invitation::query()->with(['role', 'inviter'])->where('token_hash', Invitation::hashToken($token))->first();
            $tenant = $invitation !== null ? Tenant::query()->find($invitation->tenant_id) : null;
            if ($invitation === null || $tenant === null) {
                return null;
            }

            $status = match (true) {
                $invitation->accepted_at !== null => 'accepted',
                $invitation->revoked_at !== null => 'revoked',
                ! $invitation->expires_at->isFuture() => 'expired',
                default => 'pending',
            };

            return [
                'status' => $status,
                'workspace' => $tenant->name,
                'role' => $invitation->role?->name,
                'invited_by' => $invitation->inviter?->name,
                'invited_email' => self::maskEmail($invitation->email),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                // Only for a signed-in viewer: whether this account is the invited one.
                'for_you' => $viewer !== null ? self::sameEmail($invitation->email, (string) $viewer->email) : null,
            ];
        });
    }

    public static function sameEmail(string $a, string $b): bool
    {
        return hash_equals(mb_strtolower(trim($a)), mb_strtolower(trim($b)));
    }

    /** "wa•••@example.com": enough for the invitee to recognise, not enough to harvest. */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).'•••@'.$domain;
    }
}
