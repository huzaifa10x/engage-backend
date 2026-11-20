<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Access\PhoneVisibility;
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
 * @property ?string $source_detail the link that brought the contact in (source wa_link)
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
 * @property ?Carbon $consent_requested_at
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
        'consent_state', 'opted_in_at', 'opted_out_at', 'consent_requested_at', 'marketing_opted_out', 'last_inbound_at', 'synced_from_phone_number_id',
        'company', 'job_title', 'city', 'country', 'language', 'lead_status', 'owner_membership_id', 'contact_permission',
    ];

    /**
     * The contact's current CRM pipeline stage. Changed only through EnquiryStages: the current enquiry's stage (history kept).
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
            'contact_permission' => 'array',
            'tags' => PgTextArray::class,
            'consent_state' => ConsentState::class,
            'opted_in_at' => 'datetime',
            'opted_out_at' => 'datetime',
            'consent_requested_at' => 'datetime',
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
        // A contact known only by number shows it, masked for teammates who may not see numbers (item 13).
        return $this->name ?? $this->profile_name ?? $this->username ?? ($this->wa_id ? (string) app(PhoneVisibility::class)->show($this->wa_id) : 'WhatsApp user');
    }

    /**
     * May this contact receive a business-initiated message of this template category?
     * Never to someone who opted out (STOP) or stopped marketing in WhatsApp. Marketing also needs a
     * recorded opt-in, unless the sender chose to include contacts who have not opted in
     * ($requireMarketingOptIn = false: a campaign's "Include contacts who have not opted in").
     *
     * @return ?string null when allowed, otherwise the reason it is not
     */
    /**
     * Why a template may not go to this contact (null: it may). Marketing: never to anyone who opted
     * out (STOP) or stopped marketing messages in WhatsApp; Unknown and opted-in contacts receive it.
     * Utility and authentication templates (order updates, codes) go to every contact with a number.
     */
    public function campaignBlocker(?string $category, bool $optedInOnly = false): ?string
    {
        if ($this->wa_id === null && $this->bsuid === null) {
            return 'No WhatsApp number';
        }
        if (strtoupper((string) $category) === 'MARKETING') {
            if ($this->consent_state === ConsentState::OptedOut) {
                return 'Opted out';
            }
            if ($this->marketing_opted_out) {
                return 'Opted out of marketing';
            }
            if ($optedInOnly && $this->consent_state !== ConsentState::OptedIn) {
                return 'No consent record (this campaign sends only to opted-in contacts)';
            }
        }

        return null;
    }

    /**
     * Consent for marketing, in three states: opted_in (a recorded opt-in), opted_out (STOP, the
     * WhatsApp setting, manual, or "No" to the consent question) or unknown (no record either way).
     */
    public function marketingConsent(): string
    {
        return match (true) {
            $this->consent_state === ConsentState::OptedOut || $this->marketing_opted_out => 'opted_out',
            $this->consent_state === ConsentState::OptedIn => 'opted_in',
            default => 'unknown',
        };
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
