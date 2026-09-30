<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class TenantRequired extends DomainException
{
    public function __construct(string $message = 'You are not a member of any active workspace.')
    {
        parent::__construct($message);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantRequired;
    }

    public function status(): int
    {
        return 403;
    }
}
