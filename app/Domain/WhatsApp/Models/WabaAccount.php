<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Enums\WabaStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_id
 * @property ?string $access_token_id
 * @property ?string $name
 * @property ?string $business_name
 * @property WabaStatus $status
 * @property bool $is_subscribed_to_webhooks
 * @property ?Carbon $connected_at
 * @property ?Carbon $disconnected_at
 * @property ?MetaAccessToken $accessToken
 */
class WabaAccount extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'waba_id', 'access_token_id', 'name', 'meta_business_id', 'business_name', 'currency', 'timezone_id',
        'message_template_namespace', 'account_review_status', 'ban_state', 'health_status', 'capabilities', 'status',
        'is_subscribed_to_webhooks', 'connected_at', 'disconnected_at', 'disconnect_reason', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WabaStatus::class,
            'health_status' => 'array',
            'capabilities' => 'array',
            'is_subscribed_to_webhooks' => 'boolean',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MetaAccessToken, $this> */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(MetaAccessToken::class, 'access_token_id');
    }

    /** @return HasMany<PhoneNumber, $this> */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }
}
