<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chatbot: a graph of nodes edited as a draft and published as numbered versions. At most one
 * flow is live (is_active) per WhatsApp number; it only ever answers on that number.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property ?string $phone_number_id
 * @property bool $is_active
 * @property array<string, mixed> $draft
 * @property ?array<string, mixed> $settings
 * @property ?int $published_version
 * @property ?Carbon $published_at
 * @property ?Carbon $draft_updated_at
 * @property ?string $created_by_membership_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ChatbotFlow extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'phone_number_id', 'is_active', 'draft', 'settings', 'published_version', 'published_at', 'draft_updated_at', 'created_by_membership_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'draft' => 'array',
            'settings' => 'array',
            'published_version' => 'integer',
            'published_at' => 'datetime',
            'draft_updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhoneNumber, $this> */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /** @return HasMany<ChatbotFlowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ChatbotFlowVersion::class, 'flow_id');
    }

    public function publishedGraph(): ?ChatbotFlowVersion
    {
        return $this->published_version === null ? null
            : ChatbotFlowVersion::query()->where('flow_id', $this->id)->where('version', $this->published_version)->first();
    }

    /** Draft differs from what is live (shown as "unpublished changes"). */
    public function hasUnpublishedChanges(): bool
    {
        return $this->published_at === null || ($this->draft_updated_at !== null && $this->draft_updated_at->gt($this->published_at));
    }

    /** The flow answering on a number right now, if any. */
    public static function liveFor(string $phoneNumberId): ?self
    {
        return self::query()->where('phone_number_id', $phoneNumberId)->where('is_active', true)->whereNotNull('published_version')->first();
    }
}
