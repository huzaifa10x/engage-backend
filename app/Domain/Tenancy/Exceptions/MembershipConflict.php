<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class MembershipConflict extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::Conflict;
    }

    public function status(): int
    {
        return 409;
    }
}
