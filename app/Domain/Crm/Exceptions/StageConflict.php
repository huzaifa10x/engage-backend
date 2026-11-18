<?php

declare(strict_types=1);

namespace App\Domain\Crm\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** The lead's stage changed since the screen loaded: nothing is overwritten (item 4). */
final class StageConflict extends DomainException
{
    public static function by(?string $name, int $version): self
    {
        return new self('This lead was updated by '.($name ?? 'someone else').'. Check its stage and try again.', ['version' => $version, 'updated_by' => $name]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::Conflict;
    }

    public function status(): int
    {
        return 409;
    }
}
