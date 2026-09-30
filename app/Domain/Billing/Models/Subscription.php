<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_version_id
 * @property SubscriptionStatus $status
 * @property BillingProvider $provider
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $current_period_end
 * @property ?PlanVersion $planVersion
 */
class Subscription extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'plan_version_id', 'status', 'provider', 'provider_customer_id', 'provider_subscription_id',
        'billing_interval', 'trial_ends_at', 'current_period_start', 'current_period_end',
        'cancel_at', 'canceled_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'provider' => BillingProvider::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /** @return HasMany<SubscriptionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::live()));
    }
}
