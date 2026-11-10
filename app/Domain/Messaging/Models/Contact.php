<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Crm\Models\CrmStage;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Database\PgTextArray;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A WhatsApp user as seen by one workspace. Identified by phone (wa_id), BSUID, or both.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $wa_id
 * @property ?string $bsuid
 * @property ?string $parent_bsuid
 * @property ?string $username
 * @property ?string $profile_name
 * @property ?string $name
 * @property ?string $email
 * @property ?array<string, mixed> $custom_fields
 * @property list<string> $tags
 * @property string $source
 * @property ?string $synced_from_phone_number_id
 * @property ?PhoneNumber $syncedFrom
 * @property ConsentState $consent_state
 * @property ?Carbon $opted_out_at
 * @property bool $marketing_opted_out
 * @property ?Carbon $last_inbound_at
 * @property ?string $stage_id
 * @property ?Carbon $stage_changed_at
 * @property ?CrmStage $stage
 */
class Contact extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Mirror the column defaults so freshly created models are complete without a refetch. */
    protected $attributes = [
        'source' => 'inbound',
        'consent_state' => 'unknown',
        'marketing_opted_out' => false,
    ];

    protected $fillable = [
        'tenant_id', 'wa_id', 'bsuid', 'parent_bsuid', 'username', 'profile_name', 'name', 'email', 'custom_fields', 'tags', 'source',
        'consent_state', 'opted_in_at', 'opted_out_at', 'marketing_opted_out', 'last_inbound_at', 'synced_from_phone_number_id',
    ];

    /**
     * The contact's current CRM pipeline stage. Changed only through ContactStages (history kept).
     *
     * @return BelongsTo<CrmStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmStage::class, 'stage_id');
    }

    /**
     * The number whose WhatsApp Business app this contact was synced from, if it came from a sync.
     *
     * @return BelongsTo<PhoneNumber, $this>
     */
    public function syncedFrom(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'synced_from_phone_number_id');
    }

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'tags' => PgTextArray::class,
            'consent_state' => ConsentState::class,
            'opted_in_at' => 'datetime',
            'opted_out_at' => 'datetime',
            'marketing_opted_out' => 'boolean',
            'last_inbound_at' => 'datetime',
            // Growth Hub projection (written only by EvidenceRecorder).
            'acquisition_original' => 'array',
            'acquisition_latest' => 'array',
            'first_tracked_enquiry_at' => 'datetime',
            'stage_changed_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return $this->name ?? $this->profile_name ?? $this->username ?? ($this->wa_id ? '+'.$this->wa_id : 'WhatsApp user');
    }

    /**
     * May this contact receive a business-initiated message of this template category?
     * Never to someone who opted out (STOP) or stopped marketing in WhatsApp. Marketing also needs a
     * recorded opt-in, unless the sender chose to include contacts who have not opted in
     * ($requireMarketingOptIn = false: a campaign's "Include contacts who have not opted in").
     *
     * @return ?string null when allowed, otherwise the reason it is not
     */
    public function campaignBlocker(?string $category, bool $requireMarketingOptIn = true): ?string
    {
        if ($this->wa_id === null && $this->bsuid === null) {
            return 'No WhatsApp number';
        }
        if ($this->consent_state === ConsentState::OptedOut) {
            return 'Opted out';
        }
        if (strtoupper((string) $category) === 'MARKETING') {
            if ($this->marketing_opted_out) {
                return 'Stopped marketing messages in WhatsApp';
            }
            if ($requireMarketingOptIn && $this->consent_state !== ConsentState::OptedIn) {
                return 'No marketing opt-in';
            }
        }

        return null;
    }

    public function isOptedOut(): bool
    {
        return $this->consent_state === ConsentState::OptedOut;
    }

    /**
     * Cloud API addressing: phone takes precedence; BSUID when the phone is unknown.
     *
     * @return array<string, string>
     */
    public function recipient(): array
    {
        return $this->wa_id !== null ? ['to' => $this->wa_id] : ['recipient' => (string) $this->bsuid];
    }

    /**
     * A number typed by a person, checked: the Cloud API digits, or a validation error on $field.
     * Numbers without a country code are read in the workspace's country (Settings › Company).
     */
    public static function checkedPhone(string $phone, string $field = 'phone'): string
    {
        $result = PhoneNumbers::parse($phone, self::workspaceCountry());

        return $result['digits'] ?? throw ValidationException::withMessages([$field => $result['error']]);
    }

    /** Like checkedPhone, without the error (null when the number is not valid). */
    public static function validPhone(string $phone): ?string
    {
        return PhoneNumbers::normalize($phone, self::workspaceCountry());
    }

    private static function workspaceCountry(): ?string
    {
        return app(TenantContext::class)->tenantOrNull()?->country;
    }

    /** Normalise a phone to Cloud API digits (no '+', no spaces), without checking the country rules. */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim(str_starts_with(trim($phone), '00') ? substr($digits, 2) : $digits, '0');

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }
}
