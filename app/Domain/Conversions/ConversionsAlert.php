<?php

declare(strict_types=1);

namespace App\Domain\Conversions;

use RuntimeException;

/**
 * A Conversions to Meta monitoring alert, reported to Sentry. One factory per rule, so Sentry groups
 * (and our reporter de-duplicates) each rule on its own.
 */
final class ConversionsAlert extends RuntimeException
{
    public static function successRate(string $message): self
    {
        return new self($message);
    }

    public static function queued(string $message): self
    {
        return new self($message);
    }
}
