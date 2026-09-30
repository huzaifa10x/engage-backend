<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\Exceptions\TenantContextMissing;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fail-closed tenant filter. A tenant-owned model queried with no active tenant (and outside an
 * explicit bypass) throws instead of silently returning every tenant's rows.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassing()) {
            return;
        }

        if (! $context->check()) {
            throw TenantContextMissing::forModel($model);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
