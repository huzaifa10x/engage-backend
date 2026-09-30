<?php

declare(strict_types=1);

namespace App\Domain\Plans\Models;

use App\Domain\Plans\Enums\PlanVersionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $plan_id
 * @property int $version
 * @property PlanVersionStatus $status
 * @property ?int $price_monthly_minor
 * @property ?int $price_yearly_minor
 * @property string $currency
 * @property int $trial_days
 * @property ?Plan $plan
 */
class PlanVersion extends Model
{
    use HasUuids;

    protected $fillable = ['plan_id', 'version', 'status', 'price_monthly_minor', 'price_yearly_minor', 'currency', 'trial_days', 'published_at'];

    protected function casts(): array
    {
        return [
            'status' => PlanVersionStatus::class,
            'version' => 'integer',
            'price_monthly_minor' => 'integer',
            'price_yearly_minor' => 'integer',
            'trial_days' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<PlanVersionFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanVersionFeature::class);
    }
}
