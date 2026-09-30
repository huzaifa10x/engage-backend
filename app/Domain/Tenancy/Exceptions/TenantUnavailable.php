<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class TenantUnavailable extends DomainException
{
    public function __construct(TenantStatus $status)
    {
        parent::__construct('This workspace is not available.', ['status' => $status->value]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantUnavailable;
    }

    public function status(): int
    {
        return 403;
    }
}
