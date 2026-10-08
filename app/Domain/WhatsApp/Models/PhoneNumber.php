<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A registered business number. `max_mps` and `capabilities` are read by the outbound queue
 * and the UI: coexistence numbers are fixed at 20 mps and lose app broadcast lists / groups.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_account_id
 * @property string $phone_number_id
 * @property ?string $display_phone_number
 * @property ?string $verified_name
 * @property ?string $quality_rating
 * @property ?string $messaging_limit_tier
 * @property PhoneNumberStatus $status
 * @property OnboardingType $onboarding_type
 * @property CoexistenceStatus $coexistence_status
 * @property int $max_mps
 * @property ?array<string, mixed> $capabilities
 * @property ?string $pin_secret_id
 * @property ?Carbon $app_sync_expires_at
 * @property ?WabaAccount $wabaAccount
 */
class PhoneNumber extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'waba_account_id', 'phone_number_id', 'display_phone_number', 'e164', 'verified_name', 'name_status',
        'quality_rating', 'messaging_limit_tier', 'throughput_level', 'code_verification_status', 'platform_type',
        'is_official_business_account', 'is_on_biz_app', 'status', 'onboarding_type', 'coexistence_status',
        'app_sync_started_at', 'app_sync_expires_at', 'max_mps', 'capabilities', 'pin_secret_id', 'registered_at', 'last_synced_at',
    ];

    protected $hidden = ['pin_secret_id'];

    protected function casts(): array
    {
        return [
            'status' => PhoneNumberStatus::class,
            'onboarding_type' => OnboardingType::class,
            'coexistence_status' => CoexistenceStatus::class,
            'is_official_business_account' => 'boolean',
            'is_on_biz_app' => 'boolean',
            'capabilities' => 'array',
            'max_mps' => 'integer',
            'app_sync_started_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'app_sync_expires_at' => 'datetime',
            'registered_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WabaAccount, $this> */
    public function wabaAccount(): BelongsTo
    {
        return $this->belongsTo(WabaAccount::class);
    }

    /** @param Builder<self> $query */
    public function scopeConnected(Builder $query): void
    {
        $query->where('status', PhoneNumberStatus::Connected);
    }

    public function isCoexistence(): bool
    {
        return $this->onboarding_type === OnboardingType::Coexistence;
    }

    /** @return array<string, bool> */
    public static function capabilitiesFor(OnboardingType $type): array
    {
        $coexistence = $type === OnboardingType::Coexistence;

        return [
            'broadcasts' => true,
            'app_broadcast_lists' => ! $coexistence,  // read-only in the app after coexistence onboarding
            'groups' => ! $coexistence,
            'calls' => ! $coexistence,
            'deregister' => ! $coexistence,           // coexistence offboards from the app, not the API
            'app_echoes' => $coexistence,
        ];
    }
}
