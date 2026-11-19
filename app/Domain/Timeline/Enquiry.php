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
 * @property int $stage_version raised by every stage change; a screen sends back the one it loaded (item 4)
 * @property ?string $lost_reason
 * @property ?Carbon $stage_changed_at
 * @property ?Carbon $last_activity_at
 * @property ?string $title what the enquiry is about (3.3, B1)
 * @property ?string $value
 * @property ?string $value_period once | month | year
 * @property ?Carbon $expected_close_on
 * @property int $details_version raised by every edit of the card; a screen sends back the one it loaded
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

    /** Mirror the column default so a new enquiry is complete without a refetch. */
    protected $attributes = ['stage_version' => 0, 'details_version' => 0];

    public const PERIODS = ['once' => 'one-off', 'month' => '/ month', 'year' => '/ year'];

    protected $fillable = ['tenant_id', 'contact_id', 'number', 'conversation_id', 'started_at', 'started_by', 'start_reason', 'source', 'source_confidence',
        'source_detail', 'owner_membership_id', 'stage_id', 'stage_version', 'lost_reason', 'stage_changed_at', 'last_activity_at',
        'title', 'value', 'value_period', 'expected_close_on', 'details_version', 'details_updated_by', 'details_updated_at'];

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
        return ['started_at' => 'datetime', 'last_activity_at' => 'datetime', 'stage_changed_at' => 'datetime', 'stage_version' => 'integer', 'source_detail' => 'array', 'number' => 'integer',
            'expected_close_on' => 'date', 'details_version' => 'integer', 'details_updated_at' => 'datetime'];
    }
}
