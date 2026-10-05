<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Models;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A broadcast: one approved template sent to a segment (or to all contacts), through the same
 * queue and per-number rate limiting as every other outbound message.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $phone_number_id
 * @property ?string $message_template_id
 * @property string $template_name
 * @property string $template_language
 * @property ?string $template_category
 * @property ?array{header?: list<string>, body?: list<string>, buttons?: array<string, string>} $variables
 * @property ?string $media_id
 * @property ?string $segment_id
 * @property ?array{match: string, rules: list<array<string, mixed>>, name: ?string} $audience
 * @property CampaignStatus $status
 * @property ?Carbon $scheduled_at
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property int $matched_count
 * @property int $eligible_count
 * @property ?string $failure_reason
 * @property ?string $created_by_membership_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Campaign extends Model
{
    use BelongsToTenant, HasUuids;

    protected $attributes = ['status' => 'draft', 'matched_count' => 0, 'eligible_count' => 0];

    protected $fillable = [
        'tenant_id', 'name', 'phone_number_id', 'message_template_id', 'template_name', 'template_language', 'template_category',
        'variables', 'media_id', 'segment_id', 'audience', 'status', 'scheduled_at', 'started_at', 'completed_at',
        'matched_count', 'eligible_count', 'failure_reason', 'created_by_membership_id',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'audience' => 'array',
            'status' => CampaignStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhoneNumber, $this> */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
