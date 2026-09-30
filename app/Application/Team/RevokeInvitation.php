<?php

declare(strict_types=1);

namespace App\Application\Team;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Invitation;

final class RevokeInvitation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Invitation $invitation): void
    {
        if ($invitation->revoked_at !== null || $invitation->accepted_at !== null) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('invitation.revoked', $invitation, before: ['email' => $invitation->email]);
    }
}
