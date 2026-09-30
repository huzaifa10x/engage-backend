<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CrossTenantWriteDetected extends LogicException
{
    public static function forModel(Model $model, string $attempted, string $expected): self
    {
        return new self(sprintf('Refusing to write [%s] with tenant_id %s while tenant %s is active.', $model::class, $attempted, $expected));
    }
}
