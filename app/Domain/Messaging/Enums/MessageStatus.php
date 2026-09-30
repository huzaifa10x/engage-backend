<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';       // created, waiting for the send job
    case Accepted = 'accepted';   // Cloud API accepted it and returned a wamid
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Received = 'received';   // inbound
    case Deleted = 'deleted';     // revoked by the customer

    /** Outbound progression; statuses arrive out of order, only forward moves are applied. */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Accepted => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
            default => -1,
        };
    }

    public static function fromWebhook(string $status): ?self
    {
        return match (strtolower($status)) {
            'sent' => self::Sent,
            'delivered' => self::Delivered,
            'read', 'played' => self::Read,
            'failed', 'error' => self::Failed,
            'deleted' => self::Deleted,
            'pending' => self::Accepted,
            default => null,
        };
    }
}
