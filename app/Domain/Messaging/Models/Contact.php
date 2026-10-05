<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Database\PgTextArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property ConsentState $consent_state
 * @property ?Carbon $opted_out_at
 * @property bool $marketing_opted_out
 * @property ?Carbon $last_inbound_at
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
        'consent_state', 'opted_in_at', 'opted_out_at', 'marketing_opted_out', 'last_inbound_at',
    ];

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
        ];
    }

    public function displayName(): string
    {
        return $this->name ?? $this->profile_name ?? $this->username ?? ($this->wa_id ? '+'.$this->wa_id : 'WhatsApp user');
    }

    /**
     * May this contact receive a business-initiated message of this template category?
     * Marketing needs an explicit opt-in (a plain inbound message only opens the 24-hour window);
     * utility / authentication messages go to anyone who has not opted out.
     *
     * @return ?string null when allowed, otherwise the reason it is not
     */
    public function campaignBlocker(?string $category): ?string
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
            if ($this->consent_state !== ConsentState::OptedIn) {
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

    /** Normalise a user-entered phone to Cloud API digits (no '+', no spaces). */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim(str_starts_with(trim($phone), '00') ? substr($digits, 2) : $digits, '0');

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }
}
