<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class LastOwner extends DomainException
{
    public function __construct()
    {
        parent::__construct('A workspace must keep at least one owner. Transfer ownership first.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::LastOwner;
    }
}
