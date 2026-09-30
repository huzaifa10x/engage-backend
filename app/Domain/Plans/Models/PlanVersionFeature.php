<?php

declare(strict_types=1);

namespace App\Domain\Plans\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $plan_version_id
 * @property string $feature_id
 * @property bool $enabled
 * @property ?int $limit_value NULL = unlimited
 * @property array<string, mixed>|null $config
 * @property ?Feature $feature
 */
class PlanVersionFeature extends Model
{
    use HasUuids;

    protected $fillable = ['plan_version_id', 'feature_id', 'enabled', 'limit_value', 'config'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit_value' => 'integer', 'config' => 'array'];
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
