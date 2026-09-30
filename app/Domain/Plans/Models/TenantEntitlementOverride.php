<?php

declare(strict_types=1);

namespace App\Domain\Plans\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant adjustments set by the Super Admin (enterprise deals, add-ons, design-partner perks).
 * NULL columns mean "inherit from the plan".
 *
 * @property ?bool $enabled
 * @property ?int $limit_value
 * @property array<string, mixed>|null $config
 * @property ?Feature $feature
 */
class TenantEntitlementOverride extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'feature_id', 'enabled', 'limit_value', 'unlimited', 'config', 'reason', 'expires_at', 'created_by_admin_id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'limit_value' => 'integer',
            'unlimited' => 'boolean',
            'config' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
