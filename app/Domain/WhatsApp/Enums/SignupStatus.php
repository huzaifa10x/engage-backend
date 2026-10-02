<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

enum SignupStatus: string
{
    case Started = 'started';
    /** IDs returned by the popup are stored; nothing has been exchanged, checked or subscribed yet. */
    case Captured = 'signup_captured';
    case Exchanging = 'exchanging';
    case Provisioning = 'provisioning';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
