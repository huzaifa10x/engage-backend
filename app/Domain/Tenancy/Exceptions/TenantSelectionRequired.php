<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class TenantSelectionRequired extends DomainException
{
    public function __construct(string $message = 'Select a workspace to continue.')
    {
        parent::__construct($message);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantSelectionRequired;
    }

    public function status(): int
    {
        return 409;
    }
}
