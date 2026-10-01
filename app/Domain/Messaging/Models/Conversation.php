<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One thread per business number × contact (WhatsApp model). Carries the customer service
 * window: free-form messages are only allowed while window_expires_at is in the future.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phone_number_id
 * @property string $contact_id
 * @property ConversationStatus $status
 * @property ?string $assigned_membership_id
 * @property int $unread_count
 * @property ?Carbon $last_message_at
 * @property ?Carbon $last_inbound_at
 * @property ?Carbon $window_expires_at
 * @property ?Contact $contact
 * @property ?PhoneNumber $phoneNumber
 */
class Conversation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $attributes = [
        'status' => 'open',
        'unread_count' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'phone_number_id', 'contact_id', 'status', 'assigned_membership_id', 'unread_count', 'last_message_at',
        'last_message_preview', 'last_message_direction', 'last_inbound_at', 'window_expires_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'window_expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isWindowOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
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

    /** @return BelongsTo<TenantMembership, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'assigned_membership_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
