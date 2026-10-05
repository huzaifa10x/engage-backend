<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class CampaignException extends DomainException
{
    public static function notEditable(string $status): self
    {
        return new self("This campaign is {$status} and can no longer be changed.");
    }

    public static function cannot(string $message): self
    {
        return new self($message);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::Conflict;
    }

    public function status(): int
    {
        return 409;
    }
}
