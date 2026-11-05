<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\CommerceLinkToken;
use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Commerce\Models\CommerceOrderItem;
use App\Domain\Messaging\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How an order is shown: in Growth Hub › Sales (with its evidence), in the conversation sidebar
 * (orders and total spend, never ad cost) and in exports. Money as strings with two decimals, in the
 * order's currency.
 */
final class SalesPresenter
{
    /** "Order from a tracked chat link. This shows the chat was part of the purchase, not that it caused the sale." */
    public const EXPLAIN = [
        CommerceOrder::LINK => 'The customer bought through a link sent in the chat. This shows the chat was part of the purchase, not that it caused the sale.',
        CommerceOrder::REVIEW => 'The order came from a chat link, but its phone differs from the chat contact. It is not attached or counted until someone reviews it.',
        CommerceOrder::PHONE => 'The billing phone is a WhatsApp contact. This identifies the buyer but doesn\'t prove the chat caused the sale, so it never counts as chat or campaign revenue.',
        CommerceOrder::UNAVAILABLE => 'A chat link was sent, but the order arrived without its token (for example the customer switched device). It is kept and shown, not credited.',
        CommerceOrder::NONE => 'No chat link and no WhatsApp contact with this phone: a website order. WhatsApp can\'t be credited.',
    ];

    /** @return array<string, mixed> */
    public static function row(CommerceOrder $o, ?Contact $contact = null): array
    {
        return [
            'id' => $o->id,
            'store' => $o->store_name,
            'number' => $o->number,
            'status' => $o->status,
            'currency' => $o->currency,
            'total' => self::money($o->total),
            'refunded' => self::money($o->refunded),
            'net' => self::money($o->net),
            'paid' => $o->paid,
            'items_count' => $o->items_count,
            'match' => $o->match,
            'match_label' => CommerceOrder::LABELS[$o->match] ?? $o->match,
            'counts_as_chat_revenue' => $o->match === CommerceOrder::LINK,
            'customer' => $contact !== null
                ? ['id' => $contact->id, 'display_name' => $contact->displayName(), 'phone' => $contact->wa_id !== null ? '+'.$contact->wa_id : null]
                : ['id' => null, 'display_name' => $o->billing_name ?? 'Store customer', 'phone' => $o->billing_phone !== null ? '+'.$o->billing_phone : null],
            'conversation_id' => $o->conversation_id,
            'original_source' => self::source($o->original_source),
            'conversation_source' => self::source($o->conversation_source),
            'placed_at' => $o->placed_at->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function details(CommerceOrder $o, ?Contact $contact = null): array
    {
        /** @var ?CommerceLinkToken $token */
        $token = $o->link_token_id !== null ? CommerceLinkToken::query()->find($o->link_token_id) : null;
        $sender = $token?->sent_by_membership_id !== null
            ? DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->where('tenant_memberships.id', $token->sent_by_membership_id)->value('users.name')
            : null;

        return self::row($o, $contact) + [
            'explain' => self::EXPLAIN[$o->match] ?? null,
            'match_note' => $o->match_note,
            'match_reviewed' => $o->match_reviewed,
            'billing_phone' => $o->billing_phone !== null ? '+'.$o->billing_phone : null,
            'store_source' => $o->store_source,
            'first_chat_at' => $o->first_chat_at?->toIso8601String(),
            'hours_to_order' => $o->first_chat_at !== null ? round(max(0, $o->first_chat_at->diffInSeconds($o->placed_at)) / 3600, 1) : null,
            'completed_at' => $o->completed_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            'refunded_at' => $o->refunded_at?->toIso8601String(),
            'link' => $token === null ? null : [
                'sent_at' => $token->created_at?->toIso8601String(),
                'sent_by' => $token->sent_by === 'agent' ? ($sender ?? 'An agent') : 'Chatbot',
                'url' => $token->url,
                'expires_at' => $token->expires_at->toIso8601String(),
                'conversation_id' => $token->conversation_id,
            ],
            'items' => CommerceOrderItem::query()->where('order_id', $o->id)->orderBy('name')->get()
                ->map(fn (CommerceOrderItem $i) => ['name' => $i->name, 'sku' => $i->sku, 'quantity' => $i->quantity, 'total' => self::money($i->total)])->values(),
            'events' => DB::table('commerce_order_events')->where('integration_id', $o->integration_id)->where('external_id', $o->external_id)
                ->orderBy('occurred_at')->limit(50)->get(['event', 'status', 'total', 'refunded', 'via', 'occurred_at'])
                ->map(fn (object $e) => ['event' => $e->event, 'status' => $e->status, 'total' => $e->total !== null ? self::money($e->total) : null, 'refunded' => $e->refunded !== null ? self::money($e->refunded) : null, 'via' => $e->via, 'at' => Carbon::parse($e->occurred_at)->toIso8601String()])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $touch
     * @return array{label: string, campaign: ?string, source: ?string}|null
     */
    public static function source(?array $touch): ?array
    {
        if ($touch === null || ! isset($touch['label'])) {
            return null;
        }
        $campaign = $touch['campaign'] ?? null;
        if (($campaign === null || $campaign === '') && ! empty($touch['headline'])) {
            $campaign = 'Ad: "'.$touch['headline'].'"';
        }

        return ['label' => (string) $touch['label'], 'campaign' => $campaign ?: null, 'source' => $touch['source'] ?? null];
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
