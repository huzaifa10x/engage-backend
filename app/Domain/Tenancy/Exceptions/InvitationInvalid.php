<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** details.reason: not_found | accepted | revoked | expired | email_mismatch */
final class InvitationInvalid extends DomainException
{
    private const MESSAGES = [
        'not_found' => 'This invitation link is not valid. Open the full link from the invitation email, or ask for a new invitation.',
        'accepted' => 'This invitation has already been used.',
        'revoked' => 'This invitation was cancelled by the workspace. Ask them to send a new one.',
        'expired' => 'This invitation has expired. Ask the workspace to send a new one.',
        'email_mismatch' => 'This invitation was sent to a different email address. Sign in with the invited email to accept it.',
    ];

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'This invitation is invalid or has expired.', array $details = [])
    {
        parent::__construct($message, $details);
    }

    /** @param array<string, mixed> $details */
    public static function because(string $reason, array $details = []): self
    {
        return new self(self::MESSAGES[$reason] ?? self::MESSAGES['not_found'], ['reason' => $reason] + $details);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvitationInvalid;
    }

    public function status(): int
    {
        return 410;
    }
}
