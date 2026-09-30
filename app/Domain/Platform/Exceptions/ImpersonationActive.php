<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class ImpersonationActive extends DomainException
{
    public function __construct()
    {
        parent::__construct('Workspace switching is disabled during a support session.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ImpersonationActive;
    }

    public function status(): int
    {
        return 403;
    }
}
