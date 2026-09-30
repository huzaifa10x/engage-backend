<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class MembershipSuspended extends DomainException
{
    public function __construct(string $message = 'Your access to this workspace has been suspended.')
    {
        parent::__construct($message);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MembershipSuspended;
    }

    public function status(): int
    {
        return 403;
    }
}
