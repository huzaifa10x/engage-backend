<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
