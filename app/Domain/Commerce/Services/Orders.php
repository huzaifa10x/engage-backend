<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Automation\FollowUps;
use App\Domain\Automation\Scheduler;
use App\Domain\Commerce\Models\CommerceLinkToken;
use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Commerce\Models\CommerceOrderItem;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Crm\Services\ContactStages;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Services\Acquisition;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Services\StorePhone;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Store orders → Sales (Engagement Plan v4 §V): every reported change is logged once, the order is
 * kept in its latest state, and it is matched to WhatsApp:
 *
 *   token from a chat link, phone agrees      → link        (revenue from a chat)
 *   token from a chat link, phone differs     → review      (never attached automatically)
 *   no valid token, phone is a contact who
 *     was sent a chat link in the link period → unavailable (shown, not credited)
 *   no token, phone is a contact              → phone       (contact history, own column)
 *   otherwise                                 → none        (website order)
 *
 * Revenue is the paid total minus refunds, in the store's currency, and always follows the latest
 * state (a refund or cancellation removes it). Every order with a contact stops that contact's
 * pending nudges; a paid order can move the contact to a CRM stage the client chose.
 *
 * Runs inside the store's workspace (TenantContext::run).
 */
final class Orders
{
    public const EVENTS = ['created', 'paid', 'completed', 'refunded', 'cancelled', 'failed', 'pending', 'updated'];

    /** The event a store status stands for, when the store reports only "order updated". */
    public static function eventFor(string $status, bool $created = false): string
    {
        return $created ? 'created' : match ($status) {
            'processing' => 'paid',
            'completed' => 'completed',
            'refunded' => 'refunded',
            'cancelled' => 'cancelled',
            'failed' => 'failed',
            'pending', 'on-hold' => 'pending',
            default => 'updated',
        };
    }

    public function __construct(
        private readonly ChatLinks $links,
        private readonly Scheduler $scheduler,
        private readonly ContactStages $stages,
    ) {}

    /**
     * @return array{order: ?CommerceOrder, duplicate: bool}
     */
    public function record(Integration $integration, OrderPayload $p, string $event, ?Carbon $occurredAt = null, string $via = 'plugin'): array
    {
        $event = in_array($event, self::EVENTS, true) ? $event : 'updated';
        $occurredAt ??= $p->modifiedAt ?? Carbon::now();

        $logged = DB::table('commerce_order_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $integration->tenant_id, 'integration_id' => $integration->id,
            'external_id' => $p->id, 'event' => $event, 'status' => $p->status, 'total' => $p->total, 'refunded' => $p->refunded,
            'via' => $via, 'occurred_at' => $occurredAt->copy()->utc()->startOfSecond(), 'received_at' => Carbon::now(),
        ]) > 0;
        $integration->forceFill(['last_event_at' => Carbon::now()])->save();

        /** @var ?CommerceOrder $existing */
        $existing = CommerceOrder::query()->where('integration_id', $integration->id)->where('external_id', $p->id)->first();
        if (! $logged && $existing !== null) {
            return ['order' => $existing, 'duplicate' => true]; // a retry or a repeat: already handled
        }

        $result = DB::transaction(function () use ($integration, $p, $existing) {
            /** @var ?CommerceOrder $order */
            $order = $existing === null ? null : CommerceOrder::query()->lockForUpdate()->find($existing->id);
            // An older state arriving late (retries, catch-up) never replaces a newer one.
            if ($order !== null && $order->store_updated_at !== null && $p->modifiedAt !== null && $p->modifiedAt->lt($order->store_updated_at)) {
                return ['order' => $order, 'changed' => false, 'wasPaid' => $order->paid, 'hadContact' => $order->contact_id !== null];
            }
            $wasPaid = $order !== null && $order->paid;
            $hadContact = $order?->contact_id !== null;
            $order ??= new CommerceOrder(['integration_id' => $integration->id, 'external_id' => $p->id]);

            $paid = in_array($p->status, CommerceOrder::PAID_STATUSES, true);
            $now = Carbon::now();
            $order->fill([
                'store_name' => mb_substr($integration->name, 0, 120),
                'number' => $p->number,
                'status' => $p->status,
                'currency' => $p->currency,
                'total' => $p->total,
                'refunded' => min($p->refunded, $p->total),
                'paid' => $paid,
                'net' => $paid ? max(0, round($p->total - $p->refunded, 2)) : 0,
                'items_count' => array_sum(array_column($p->items, 'quantity')),
                'billing_name' => $p->name,
                'billing_phone' => StorePhone::normalize($p->phone, $p->country, $integration->setting('default_country_code')),
                'store_source' => $p->storeSource,
                'placed_at' => $p->placedAt,
                'paid_at' => $p->paidAt ?? ($paid ? ($order->paid_at ?? $now) : $order->paid_at),
                'completed_at' => $p->completedAt ?? ($p->status === 'completed' ? ($order->completed_at ?? $now) : $order->completed_at),
                'cancelled_at' => $p->status === 'cancelled' ? ($order->cancelled_at ?? $now) : $order->cancelled_at,
                'refunded_at' => $p->refunded > 0 ? ($order->refunded_at ?? $now) : $order->refunded_at,
                'store_updated_at' => $p->modifiedAt ?? $now,
            ]);

            if (! $order->match_reviewed && ! in_array($order->match, [CommerceOrder::LINK, CommerceOrder::REVIEW], true)) {
                $this->match($order, $integration, $p);
            }
            $order->save();

            if ($p->items !== [] || ! $order->wasRecentlyCreated) {
                CommerceOrderItem::query()->where('order_id', $order->id)->delete();
                foreach ($p->items as $item) {
                    CommerceOrderItem::query()->create(['order_id' => $order->id] + $item);
                }
            }

            return ['order' => $order, 'changed' => true, 'wasPaid' => $wasPaid, 'hadContact' => $hadContact];
        });

        /** @var CommerceOrder $order */
        $order = $result['order'];
        if ($result['changed']) {
            $this->afterChange($integration, $order, ! $result['hadContact'] && $order->contact_id !== null, ! $result['wasPaid'] && $order->paid);
        }

        return ['order' => $order, 'duplicate' => false];
    }

    /** A person decided a "phone differs" order: attach it to the chat, or keep it apart. */
    public function review(CommerceOrder $order, bool $attach): CommerceOrder
    {
        if ($order->match !== CommerceOrder::REVIEW || $order->link_token_id === null) {
            return $order;
        }
        /** @var ?Integration $integration */
        $integration = Integration::query()->find($order->integration_id);
        /** @var ?CommerceLinkToken $token */
        $token = CommerceLinkToken::query()->find($order->link_token_id);

        DB::transaction(function () use ($order, $token, $attach) {
            if ($attach && $token !== null) {
                $order->fill([
                    'match' => CommerceOrder::LINK, 'contact_id' => $token->contact_id, 'conversation_id' => $token->conversation_id,
                    'conversation_source' => $token->conversation_source, 'match_note' => 'Attached after review: the phone on the order differs from the chat contact.',
                ]);
                $this->withContact($order, Contact::query()->find($token->contact_id));
                $token->forceFill(['used_order_id' => $order->id, 'used_at' => Carbon::now()])->save();
            } else {
                $contact = $order->billing_phone !== null ? Contact::query()->where('wa_id', $order->billing_phone)->first() : null;
                $order->fill(['match' => $contact !== null ? CommerceOrder::PHONE : CommerceOrder::NONE, 'contact_id' => $contact?->id, 'conversation_id' => null, 'conversation_source' => null,
                    'match_note' => 'Kept apart after review: not counted as a sale from the chat.']);
                $this->withContact($order, $contact);
            }
            $order->match_reviewed = true;
            $order->save();
        });
        if ($integration !== null && $order->contact_id !== null) {
            $this->afterChange($integration, $order, true, $order->paid);
        }

        return $order;
    }

    private function match(CommerceOrder $order, Integration $integration, OrderPayload $p): void
    {
        $byPhone = $order->billing_phone !== null ? Contact::query()->where('wa_id', $order->billing_phone)->first() : null;
        $note = null;

        if ($p->token !== null) {
            $token = $this->links->find($p->token);
            if ($token === null) {
                $note = 'The order carried a chat link token that is not valid for this workspace.';
            } elseif ($token->used_order_id !== null && $token->used_order_id !== $order->id) {
                $used = CommerceOrder::query()->whereKey($token->used_order_id)->value('number');
                $note = 'This chat link was already used for order #'.($used ?? '?').'. A link attaches one order.';
            } elseif ($token->expires_at->lt($p->placedAt)) {
                $note = 'The chat link had expired ('.$token->expires_at->toDateString().') when the order was placed.';
            } else {
                /** @var ?Contact $chatContact */
                $chatContact = Contact::query()->find($token->contact_id);
                $order->link_token_id = $token->id;
                if ($chatContact !== null && $order->billing_phone !== null && $chatContact->wa_id !== null && $chatContact->wa_id !== $order->billing_phone) {
                    $order->fill(['match' => CommerceOrder::REVIEW, 'contact_id' => $byPhone?->id, 'conversation_id' => null,
                        'conversation_source' => $token->conversation_source,
                        'match_note' => 'The order came from a chat link, but its phone differs from the chat contact. Review before it counts.']);
                    $this->withContact($order, $byPhone);

                    return;
                }
                $order->fill(['match' => CommerceOrder::LINK, 'contact_id' => $chatContact?->id, 'conversation_id' => $token->conversation_id,
                    'conversation_source' => $token->conversation_source, 'match_note' => null]);
                $this->withContact($order, $chatContact);
                $token->forceFill(['used_order_id' => $order->id, 'used_at' => Carbon::now()])->save();

                return;
            }
        }

        $order->link_token_id = null;
        $order->conversation_id = null;
        $order->conversation_source = null;
        $order->contact_id = $byPhone?->id;
        $this->withContact($order, $byPhone);
        if ($byPhone === null) {
            $order->match = $note !== null ? CommerceOrder::UNAVAILABLE : CommerceOrder::NONE;
            $order->match_note = $note;

            return;
        }

        // A chat link was sent to this person within the link period, but the order arrived without it.
        $days = max(1, (int) $integration->setting('link_days', ChatLinks::DEFAULT_DAYS));
        $sent = CommerceLinkToken::query()->where('contact_id', $byPhone->id)->whereNull('used_order_id')
            ->where('created_at', '<=', $p->placedAt)->where('created_at', '>=', $p->placedAt->copy()->subDays($days))
            ->orderByDesc('created_at')->first();
        if ($note !== null || $sent !== null) {
            $order->match = CommerceOrder::UNAVAILABLE;
            $order->match_note = $note ?? 'A chat link was sent on '.($sent->created_at?->toDateString() ?? 'an earlier day').', but the order came without it (another device or browser).';

            return;
        }
        $order->match = CommerceOrder::PHONE;
        $order->match_note = null;
    }

    /** The two sources on every order with a contact, and the first chat (time from first chat to order). */
    private function withContact(CommerceOrder $order, ?Contact $contact): void
    {
        if ($contact === null) {
            $order->original_source = null;
            $order->first_chat_at = null;

            return;
        }
        $order->original_source = self::originalSource($contact);
        $first = DB::table('messages')->where('contact_id', $contact->id)->min('created_at');
        $order->first_chat_at = $first !== null ? Carbon::parse($first) : ($contact->created_at !== null ? Carbon::parse($contact->created_at) : null);
    }

    /** @return array<string, mixed> */
    public static function originalSource(Contact $contact): array
    {
        $tenant = app(TenantContext::class)->tenantOrNull();
        if ($tenant !== null && Acquisition::beforeTracking($contact, GrowthSettings::for($tenant))) {
            return ['source' => 'before', 'label' => 'Before tracking'];
        }
        $touch = $contact->getAttribute('acquisition_original');

        return is_array($touch) && isset($touch['label']) ? $touch : ['source' => 'untracked', 'label' => 'No tracked source'];
    }

    private function afterChange(Integration $integration, CommerceOrder $order, bool $newlyLinked, bool $newlyPaid): void
    {
        if ($order->contact_id === null) {
            return;
        }
        if ($newlyLinked && ! in_array($order->status, ['cancelled', 'failed'], true)) {
            // "Stop when the customer … places an order": no more nudges to this person.
            $this->scheduler->cancelForContact($order->contact_id, 'order_placed', FollowUps::NUDGE_KINDS);
        }

        $stageId = $integration->setting('paid_stage_id');
        if (($newlyPaid || $newlyLinked) && $order->paid && $order->stage_moved_at === null && is_string($stageId)
            && in_array($order->match, [CommerceOrder::LINK, CommerceOrder::PHONE], true) && CrmStage::query()->active()->whereKey($stageId)->exists()) {
            try {
                /** @var ?Contact $contact */
                $contact = Contact::query()->find($order->contact_id);
                if ($contact !== null) {
                    $this->stages->move($contact, $stageId, 'automation');
                }
                $order->forceFill(['stage_moved_at' => Carbon::now()])->save();
            } catch (Throwable $e) {
                Log::warning('Paid order could not move the CRM stage', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
