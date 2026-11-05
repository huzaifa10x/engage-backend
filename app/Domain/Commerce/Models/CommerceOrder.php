<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A store order in its latest state, and how it is connected to WhatsApp.
 *
 * match:
 *  - link         Order from a tracked chat link: the signed token from a chat came back on the order
 *                 and the phone agrees. The only orders counted as revenue from a chat.
 *  - review       The token came back but the order's phone differs from the chat's contact: kept for
 *                 review, never attached (or counted) automatically.
 *  - phone        Order from a WhatsApp contact: the billing phone is a contact. On the contact's history
 *                 and in its own column; never in a campaign's or chat's revenue.
 *  - unavailable  Attribution unavailable: a chat link was sent, but the order came without a valid token
 *                 (another device, an expired link…). Shown, never dropped silently, never credited.
 *  - none         Website order: no token and no contact with that phone.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_id
 * @property string $store_name
 * @property string $external_id
 * @property string $number
 * @property string $status
 * @property string $currency
 * @property string $total
 * @property string $refunded
 * @property string $net
 * @property bool $paid
 * @property int $items_count
 * @property ?string $billing_phone
 * @property ?string $billing_name
 * @property ?string $contact_id
 * @property ?string $conversation_id
 * @property ?string $link_token_id
 * @property string $match
 * @property ?string $match_note
 * @property bool $match_reviewed
 * @property ?array<string, mixed> $original_source
 * @property ?array<string, mixed> $conversation_source
 * @property ?array<string, mixed> $store_source
 * @property ?Carbon $first_chat_at
 * @property Carbon $placed_at
 * @property ?Carbon $paid_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $cancelled_at
 * @property ?Carbon $refunded_at
 * @property ?Carbon $store_updated_at
 * @property ?Carbon $stage_moved_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CommerceOrder extends Model
{
    use BelongsToTenant, HasUuids;

    public const LINK = 'link';

    public const REVIEW = 'review';

    public const PHONE = 'phone';

    public const UNAVAILABLE = 'unavailable';

    public const NONE = 'none';

    public const LABELS = [
        self::LINK => 'Order from a tracked chat link',
        self::REVIEW => 'Needs review: phone differs',
        self::PHONE => 'Order from a WhatsApp contact',
        self::UNAVAILABLE => 'Attribution unavailable',
        self::NONE => 'Website order',
    ];

    /** Store statuses that mean the customer paid (WooCommerce). */
    public const PAID_STATUSES = ['processing', 'completed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid' => 'boolean', 'match_reviewed' => 'boolean', 'items_count' => 'integer',
            'original_source' => 'array', 'conversation_source' => 'array', 'store_source' => 'array',
            'first_chat_at' => 'datetime', 'placed_at' => 'datetime', 'paid_at' => 'datetime', 'completed_at' => 'datetime',
            'cancelled_at' => 'datetime', 'refunded_at' => 'datetime', 'store_updated_at' => 'datetime', 'stage_moved_at' => 'datetime',
        ];
    }

    /** @return HasMany<CommerceOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CommerceOrderItem::class, 'order_id');
    }
}
