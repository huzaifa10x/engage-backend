<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

enum SignupStatus: string
{
    case Started = 'started';
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
