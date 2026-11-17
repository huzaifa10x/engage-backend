<?php

declare(strict_types=1);

namespace App\Domain\Timeline;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One buying conversation of a contact: a rental in March, another in June.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property int $number
 * @property ?string $conversation_id
 * @property Carbon $started_at
 * @property string $started_by customer | agent | system
 * @property string $start_reason first_message | quiet_period | ad_click | manual | backfill | imported
 * @property string $source ad | link | website | direct
 * @property string $source_confidence verified | partial | unknown
 * @property ?array<string, mixed> $source_detail
 * @property ?string $owner_membership_id
 * @property ?string $stage_id
 * @property ?Carbon $last_activity_at
 * @property ?Carbon $created_at
 */
class Enquiry extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * Built from chat history imported from the WhatsApp Business app: shown as "Imported" and left out
     * of reply-time reports and the audit baseline (the team could not have answered it in Engage).
     */
    public const IMPORTED = 'imported';

    public const SOURCE_LABELS = ['ad' => 'Ad', 'link' => 'WhatsApp link or QR code', 'website' => 'Website', 'direct' => 'Direct'];

    public const CONFIDENCE_LABELS = ['verified' => 'Verified', 'partial' => 'Partial', 'unknown' => 'Unknown'];

    protected $fillable = ['tenant_id', 'contact_id', 'number', 'conversation_id', 'started_at', 'started_by', 'start_reason', 'source', 'source_confidence',
        'source_detail', 'owner_membership_id', 'stage_id', 'last_activity_at'];

    public function isImported(): bool
    {
        return $this->start_reason === self::IMPORTED;
    }

    /**
     * Enquiries that happened in Engage: every report that measures the team (reply times, the audit
     * baseline) reads from this, never from imported history.
     *
     * @param  Builder<Enquiry>  $query
     * @return Builder<Enquiry>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('start_reason', '!=', self::IMPORTED);
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_activity_at' => 'datetime', 'source_detail' => 'array', 'number' => 'integer'];
    }
}
