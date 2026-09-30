<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Programmer error: tenant-owned data touched without a resolved tenant. Reported, rendered as 500. */
final class TenantContextMissing extends LogicException
{
    public static function forModel(Model $model): self
    {
        return new self(sprintf(
            'Tenant context is required to query or create [%s]. Resolve a tenant (ResolveTenant middleware / TenantContext::run) or use TenantContext::bypass() for platform operations.',
            $model::class,
        ));
    }
}
