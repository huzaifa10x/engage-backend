<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Scopes\TenantScope;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client business (workspace). Global table — rows are protected by RLS so a user can only
 * see tenants they are a member of (or the active tenant).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property string $timezone
 * @property string $locale
 * @property string $currency
 * @property ?string $country
 * @property ?string $billing_email
 * @property array<string, mixed>|null $settings
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'status', 'timezone', 'locale', 'currency', 'country',
        'billing_email', 'settings', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Relation is constrained by tenant_id already — skip the context scope (RLS still applies).
     *
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class)->withoutGlobalScope(TenantScope::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->withoutGlobalScope(TenantScope::class);
    }

    /**
     * The single live (trialing/active/past_due) subscription — unique per tenant in the DB.
     *
     * @return HasOne<Subscription, $this>
     */
    public function liveSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('status', array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::live()));
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
