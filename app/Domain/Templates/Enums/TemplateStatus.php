<?php

declare(strict_types=1);

namespace App\Domain\Templates\Enums;

/**
 * Template statuses exactly as Meta reports them (Graph `status`, webhook `event`). Stored as
 * plain strings so a status Meta adds later is kept rather than rejected.
 */
final class TemplateStatus
{
    public const APPROVED = 'APPROVED';

    public const PENDING = 'PENDING';

    public const REJECTED = 'REJECTED';

    public const PAUSED = 'PAUSED';

    public const DISABLED = 'DISABLED';

    public const IN_APPEAL = 'IN_APPEAL';

    public const PENDING_DELETION = 'PENDING_DELETION';

    public const DELETED = 'DELETED';

    /** Webhook events and API aliases → the status we store. */
    public static function normalize(?string $status): string
    {
        $status = strtoupper(trim((string) $status));

        return match ($status) {
            '' => self::PENDING,
            'REINSTATED', 'UNPAUSED' => self::APPROVED,
            'IN_REVIEW', 'PENDING_REVIEW' => self::PENDING,
            'FLAGGED' => self::APPROVED, // still sendable; quality warning is in quality_score
            default => $status,
        };
    }

    /** Only approved templates may be sent. */
    public static function sendable(string $status): bool
    {
        return $status === self::APPROVED;
    }
}
