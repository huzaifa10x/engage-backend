<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\CrossTenantWriteDetected;
use App\Domain\Tenancy\Exceptions\TenantContextMissing;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to EVERY tenant-owned model. tenant_id is always stamped from the server-side context;
 * a tenant_id that disagrees with the active context is rejected.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $tenantId = $model->getAttribute('tenant_id');

            if ($tenantId === null) {
                if (! $context->check()) {
                    throw TenantContextMissing::forModel($model);
                }

                $model->setAttribute('tenant_id', $context->id());

                return;
            }

            if ($context->check() && ! $context->isBypassing() && $tenantId !== $context->id()) {
                throw CrossTenantWriteDetected::forModel($model, (string) $tenantId, $context->id());
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantWriteDetected::forModel($model, (string) $model->getAttribute('tenant_id'), (string) $model->getOriginal('tenant_id'));
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Remove the application-layer filter only (RLS still applies). For queries already constrained
     * another way, e.g. "memberships of the authenticated user across tenants".
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
