<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** System roles are visible to everyone; custom roles only inside their own tenant. */
final class RoleVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassing()) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');

        if (! $context->check()) {
            $builder->whereNull($column);

            return;
        }

        $builder->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, $context->id()));
    }
}
