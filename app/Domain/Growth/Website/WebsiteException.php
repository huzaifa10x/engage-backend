<?php

declare(strict_types=1);

namespace App\Domain\Growth\Website;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** A Tracking Setup rule the request broke (shown to the admin as is). */
final class WebsiteException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
