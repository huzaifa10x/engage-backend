<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chatbot: a graph of nodes edited as a draft and published as numbered versions. Several flows
 * can be live on one WhatsApp number (keyword, QR, campaign-reply and ad flows beside one default
 * flow and one greeting flow); Chatbots::pick() decides which one answers. A subflow (kind =
 * subflow) is never live by itself: other flows call it with a Subflow block.
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
 * @property string $kind bot | subflow
 * @property ?string $folder
 * @property ?Carbon $archived_at
 * @property ?Carbon $activate_at
 * @property ?Carbon $deactivate_at
 * @property ?list<string> $editors membership ids allowed to edit (null = everyone who manages chatbots)
 * @property ?string $review_status requested | approved | changes_requested
 * @property ?string $review_hash
 * @property ?string $reviewed_by_membership_id
 * @property ?Carbon $reviewed_at
 * @property ?string $review_note
 * @property ?string $webhook_token
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ChatbotFlow extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['bot', 'subflow'];

    protected $attributes = ['kind' => 'bot'];

    protected $fillable = [
        'tenant_id', 'name', 'phone_number_id', 'is_active', 'draft', 'settings', 'published_version', 'published_at', 'draft_updated_at', 'created_by_membership_id',
        'kind', 'folder', 'archived_at', 'activate_at', 'deactivate_at', 'editors', 'review_status', 'review_hash', 'reviewed_by_membership_id', 'reviewed_at', 'review_note', 'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'draft' => 'array',
            'settings' => 'array',
            'published_version' => 'integer',
            'published_at' => 'datetime',
            'draft_updated_at' => 'datetime',
            'archived_at' => 'datetime',
            'activate_at' => 'datetime',
            'deactivate_at' => 'datetime',
            'editors' => 'array',
            'reviewed_at' => 'datetime',
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

    /**
     * The flows live on a number right now, oldest published first.
     *
     * @return Collection<int, self>
     */
    public static function liveOn(string $phoneNumberId): Collection
    {
        return self::query()->where('phone_number_id', $phoneNumberId)->where('is_active', true)->where('kind', 'bot')
            ->whereNotNull('published_version')->whereNull('archived_at')->orderBy('published_at')->orderBy('id')->get();
    }

    public static function anyLiveOn(string $phoneNumberId): bool
    {
        return self::query()->where('phone_number_id', $phoneNumberId)->where('is_active', true)->where('kind', 'bot')->whereNotNull('published_version')->exists();
    }

    /** What is reviewed and published: the draft graph and settings, hashed. */
    public function draftHash(): string
    {
        return hash('sha256', (string) json_encode([$this->draft, $this->settings]));
    }

    /** Agency mode: the current draft carries a reviewer's approval. */
    public function approvedForPublish(): bool
    {
        return $this->review_status === 'approved' && $this->review_hash !== null && hash_equals($this->review_hash, $this->draftHash());
    }
}
