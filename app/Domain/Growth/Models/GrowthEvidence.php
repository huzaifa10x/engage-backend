<?php

declare(strict_types=1);

namespace App\Domain\Growth\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One piece of proof of where a WhatsApp message came from: a Meta click-to-WhatsApp referral
 * (R1 Sprint 1) or a registered website reference (Sprint 3). Append-only; event-specific, never
 * a global overwrite of the contact.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $crm_contact_id
 * @property ?string $conversation_id
 * @property ?string $phone_number_id
 * @property ?string $message_id
 * @property string $wa_message_id
 * @property ?string $click_id
 * @property string $evidence_type
 * @property string $confidence
 * @property string $source
 * @property array<string, mixed> $provider_fields
 * @property bool $conflict
 * @property Carbon $occurred_at
 */
class GrowthEvidence extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_META_CTWA = 'meta_ctwa';

    protected $table = 'growth_evidence';

    protected $fillable = ['tenant_id', 'crm_contact_id', 'conversation_id', 'phone_number_id', 'message_id', 'wa_message_id', 'click_id', 'evidence_type', 'confidence', 'source', 'provider_fields', 'conflict', 'occurred_at'];

    protected function casts(): array
    {
        return ['provider_fields' => 'array', 'conflict' => 'boolean', 'occurred_at' => 'datetime'];
    }
}
