<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class InvitationInvalid extends DomainException
{
    public function __construct(string $message = 'This invitation is invalid or has expired.')
    {
        parent::__construct($message);
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
