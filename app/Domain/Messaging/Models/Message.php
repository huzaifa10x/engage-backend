<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $phone_number_id
 * @property string $contact_id
 * @property string $direction
 * @property MessageOrigin $origin
 * @property string $type
 * @property MessageStatus $status
 * @property ?string $wamid
 * @property ?string $context_wamid
 * @property ?string $body
 * @property ?array<string, mixed> $content
 * @property ?string $media_id
 * @property ?array<string, mixed> $template
 * @property ?string $error_code
 * @property ?string $error_title
 * @property ?string $sent_by_membership_id
 * @property ?Carbon $meta_timestamp
 * @property ?Carbon $occurred_at
 * @property ?Carbon $created_at
 * @property ?Conversation $conversation
 * @property ?Contact $contact
 * @property ?PhoneNumber $phoneNumber
 * @property ?Media $media
 */
class Message extends Model
{
    use BelongsToTenant, HasUuids;

    public const INBOUND = 'inbound';

    public const OUTBOUND = 'outbound';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'phone_number_id', 'contact_id', 'direction', 'origin', 'type', 'status', 'wamid',
        'context_wamid', 'body', 'content', 'media_id', 'template', 'error_code', 'error_title', 'error_details', 'pricing',
        'sent_by_membership_id', 'idempotency_key', 'meta_timestamp', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
        'edited_at', 'revoked_at', 'occurred_at',
    ];

    /**
     * When the message really happened: WhatsApp's timestamp if it has one (received messages,
     * messages sent from the phone, imported history), otherwise now. Set once, on creation;
     * conversations are sorted and paged by it.
     */
    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            if ($message->getAttribute('occurred_at') === null) {
                $message->setAttribute('occurred_at', $message->meta_timestamp ?? now());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'origin' => MessageOrigin::class,
            'status' => MessageStatus::class,
            'content' => 'array',
            'template' => 'array',
            'error_details' => 'array',
            'pricing' => 'array',
            'meta_timestamp' => 'datetime',
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'edited_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::OUTBOUND;
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /** @return BelongsTo<PhoneNumber, $this> */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** Short text for conversation lists / notifications. */
    public function preview(): string
    {
        $text = $this->body !== null && $this->body !== '' ? $this->body : match ($this->type) {
            'image' => '📷 Photo',
            'video' => '🎬 Video',
            'audio' => '🎤 Audio',
            'document' => '📄 '.($this->content['filename'] ?? 'Document'),
            'sticker' => 'Sticker',
            'location' => '📍 Location',
            'contacts' => '👤 Contact',
            'template' => '📋 '.($this->template['name'] ?? 'Template'),
            'reaction' => 'Reacted '.($this->content['emoji'] ?? ''),
            'unsupported' => 'Unsupported message',
            default => ucfirst($this->type),
        };

        return mb_substr($text, 0, 200);
    }
}
