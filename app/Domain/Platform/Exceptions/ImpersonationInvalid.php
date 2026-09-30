<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class ImpersonationInvalid extends DomainException
{
    public function __construct(string $message = 'This support link is invalid or has expired.')
    {
        parent::__construct($message);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ImpersonationInvalid;
    }

    public function status(): int
    {
        return 401;
    }
}
