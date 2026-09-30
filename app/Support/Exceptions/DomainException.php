<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Support\Api\ErrorCode;
use RuntimeException;

/**
 * Base class for expected business-rule failures. Rendered by ApiExceptionRenderer into the
 * standard error envelope and never reported to the error tracker.
 */
abstract class DomainException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message = '', protected array $details = [])
    {
        parent::__construct($message);
    }

    abstract public function errorCode(): ErrorCode;

    public function status(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
