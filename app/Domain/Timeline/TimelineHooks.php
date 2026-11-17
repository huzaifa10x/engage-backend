<?php

declare(strict_types=1);

namespace App\Domain\Timeline;

use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Crm\Models\ContactStageEvent;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Developer\Models\WebhookDelivery;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConsentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the timeline is written from: model events of the records that already exist (messages,
 * contacts, conversations, consent and stage changes, flow runs, store orders, webhook deliveries),
 * so every way a thing can happen — inbox, flows, API, imports, integrations — is covered once.
 * Changes made with bulk queries that skip model events are not recorded.
 */
final class TimelineHooks
{
    /** Consent recorded from the customer's own action. */
    private const CUSTOMER_CONSENT = ['keyword', 'in_chat_button', 'website_form', 'qr_link', 'whatsapp_form', 'flow_step', 'chatbot', 'meta_preference', 'ad'];

    /** Flow events worth a line in the history (the run log has the rest). */
    private const FLOW_KINDS = ['enter' => 'flow.step', 'click' => 'flow.answer', 'complete' => 'flow.ended', 'handoff' => 'flow.ended', 'abandon' => 'flow.ended', 'error' => 'flow.step'];

    public static function register(): void
    {
        Message::created(fn (Message $m) => self::safe(fn () => self::message($m)));
        Contact::created(fn (Contact $c) => self::safe(fn () => Timeline::record($c->id, 'contact.created', null, 'contact', $c->id,
            ['source' => $c->source, 'source_detail' => $c->getAttribute('source_detail')], $c->created_at, null)));
        Contact::updated(fn (Contact $c) => self::safe(fn () => self::tags($c)));
        Conversation::updated(fn (Conversation $c) => self::safe(fn () => self::conversation($c)));
        ConsentEvent::created(fn (ConsentEvent $e) => self::safe(fn () => self::consent($e)));
        ContactStageEvent::created(fn (ContactStageEvent $e) => self::safe(fn () => self::stage($e)));
        ChatbotEvent::created(fn (ChatbotEvent $e) => self::safe(fn () => self::flow($e)));
        CommerceOrder::saved(fn (CommerceOrder $o) => self::safe(fn () => self::order($o)));
        WebhookDelivery::updated(fn (WebhookDelivery $d) => self::safe(fn () => self::delivery($d)));
    }

    private static function message(Message $m): void
    {
        $origin = $m->origin;
        if ($origin === MessageOrigin::History) {
            return; // imported chat history is old news, not something that happened now
        }
        $at = $m->getAttribute('meta_timestamp') ?? $m->created_at;
        $data = ['type' => $m->type, 'origin' => $origin->value];
        if ($m->template !== null) {
            $data['template'] = $m->template['name'] ?? null;
        }
        if ($m->getAttribute('campaign_id') !== null) {
            $data['campaign_id'] = $m->getAttribute('campaign_id');
        }

        if ($m->direction === Message::INBOUND) {
            $enquiryId = null;
            if ($origin === MessageOrigin::Customer) {
                /** @var ?Contact $contact */
                $contact = Contact::query()->withTrashed()->find($m->contact_id);
                if ($contact !== null) {
                    $enquiryId = app(Enquiries::class)->forInbound($m, $contact)->id;
                }
            }
            Timeline::record($m->contact_id, 'message.received', Timeline::CUSTOMER, 'message', $m->id, $data, $at, $enquiryId);

            return;
        }

        $agent = in_array($origin, [MessageOrigin::Agent, MessageOrigin::AppEcho], true) && $m->sent_by_membership_id !== null;
        Timeline::record($m->contact_id, 'message.sent', $agent ? Timeline::AGENT : Timeline::SYSTEM, 'message', $m->id, $data, $at,
            null, $agent ? $m->sent_by_membership_id : null);
        if ($origin === MessageOrigin::Agent || $origin === MessageOrigin::Api) {
            DB::table('enquiries')->where('id', DB::table('contacts')->where('id', $m->contact_id)->value('current_enquiry_id'))->update(['last_activity_at' => now()]);
        }
    }

    private static function tags(Contact $c): void
    {
        if (! $c->wasChanged('tags')) {
            return;
        }
        $before = array_map('strval', (array) self::decode($c->getOriginal('tags')));
        $after = array_map('strval', (array) $c->tags);
        $lower = fn (array $a) => array_map('mb_strtolower', $a);
        foreach (array_diff($after, $before) as $tag) {
            if (! in_array(mb_strtolower($tag), $lower($before), true)) {
                Timeline::record($c->id, 'tag.added', null, 'contact', $c->id, ['tag' => $tag]);
            }
        }
        foreach (array_diff($before, $after) as $tag) {
            if (! in_array(mb_strtolower($tag), $lower($after), true)) {
                Timeline::record($c->id, 'tag.removed', null, 'contact', $c->id, ['tag' => $tag]);
            }
        }
    }

    private static function conversation(Conversation $c): void
    {
        if ($c->wasChanged('assigned_membership_id')) {
            $to = $c->assigned_membership_id;
            $name = $to !== null ? DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->where('tenant_memberships.id', $to)->value('users.name') : null;
            Timeline::record($c->contact_id, 'conversation.assigned', null, 'conversation', $c->id, ['to_membership_id' => $to, 'to_name' => $name]);
            // The enquiry's owner follows who has the chat.
            DB::table('enquiries')->where('id', DB::table('contacts')->where('id', $c->contact_id)->value('current_enquiry_id'))->update(['owner_membership_id' => $to]);
        }
        if ($c->wasChanged('status')) {
            $closed = $c->status->value === 'closed';
            Timeline::record($c->contact_id, $closed ? 'conversation.closed' : 'conversation.reopened', $closed ? null : Timeline::SYSTEM, 'conversation', $c->id);
        }
    }

    private static function consent(ConsentEvent $e): void
    {
        $source = (string) $e->source;
        $actor = in_array($source, self::CUSTOMER_CONSENT, true) ? Timeline::CUSTOMER : ($source === 'agent' ? Timeline::AGENT : Timeline::SYSTEM);
        Timeline::record((string) $e->contact_id, 'consent.changed', $actor, 'consent_event', $e->id, [
            'action' => $e->action, 'source' => $source, 'source_label' => ConsentService::SOURCE_LABELS[$source] ?? $source, 'detail' => $e->detail,
        ], $e->created_at, null, $actor === Timeline::AGENT ? $e->getAttribute('membership_id') : null);
    }

    private static function stage(ContactStageEvent $e): void
    {
        $names = CrmStage::query()->whereIn('id', array_filter([$e->from_stage_id, $e->to_stage_id]))->pluck('name', 'id');
        $actor = $e->changed_by_membership_id !== null ? Timeline::AGENT : Timeline::SYSTEM;
        Timeline::record($e->contact_id, 'stage.changed', $actor, 'stage_event', $e->id, [
            'from' => $e->from_stage_id !== null ? ($names[$e->from_stage_id] ?? null) : null,
            'to' => $e->to_stage_id !== null ? ($names[$e->to_stage_id] ?? null) : null,
            'reason' => $e->source,
        ], $e->changed_at, null, $e->changed_by_membership_id);
        DB::table('enquiries')->where('id', DB::table('contacts')->where('id', $e->contact_id)->value('current_enquiry_id'))->update(['stage_id' => $e->to_stage_id]);
    }

    private static function flow(ChatbotEvent $e): void
    {
        $type = self::FLOW_KINDS[$e->kind] ?? null;
        if ($type === null) {
            return;
        }
        $session = ChatbotSession::query()->find($e->session_id, ['id', 'contact_id', 'flow_id']);
        if ($session === null) {
            return;
        }
        $flow = DB::table('chatbot_flows')->where('id', $session->flow_id)->value('name');
        // A tap or answer is the customer's; steps the flow takes are the system's.
        Timeline::record((string) $session->contact_id, $type, $type === 'flow.answer' ? Timeline::CUSTOMER : Timeline::SYSTEM, 'flow_session', $session->id,
            array_filter(['flow' => $flow, 'step' => $e->node_id, 'kind' => $e->kind, 'detail' => $e->detail]), $e->created_at);
    }

    private static function order(CommerceOrder $o): void
    {
        if ($o->contact_id === null) {
            return;
        }
        // A store order belongs to the enquiry that was current when it was first linked to the contact.
        if ($o->getAttribute('enquiry_id') === null) {
            $enquiry = DB::table('contacts')->where('id', $o->contact_id)->value('current_enquiry_id');
            if (is_string($enquiry)) {
                DB::table('commerce_orders')->where('id', $o->id)->update(['enquiry_id' => $enquiry]);
                $o->setAttribute('enquiry_id', $enquiry);
            }
        }
        $paidNow = $o->paid && ($o->wasRecentlyCreated || $o->wasChanged('paid'));
        $refunded = $o->wasChanged('refunded') && (float) $o->refunded > (float) $o->getOriginal('refunded');
        $data = ['order' => $o->number, 'store' => $o->store_name, 'total' => (float) $o->total, 'currency' => $o->currency, 'status' => $o->status];
        if ($paidNow) {
            Timeline::record($o->contact_id, 'payment.paid', Timeline::SYSTEM, 'order', $o->id, $data + ['verified_by' => 'store'], $o->paid_at ?? now(), $o->getAttribute('enquiry_id'));
        }
        if ($refunded) {
            Timeline::record($o->contact_id, 'payment.refunded', Timeline::SYSTEM, 'order', $o->id, $data + ['refunded' => (float) $o->refunded], now(), $o->getAttribute('enquiry_id'));
        }
    }

    private static function delivery(WebhookDelivery $d): void
    {
        if (! $d->wasChanged('status') || ! in_array($d->status, ['delivered', 'failed'], true)) {
            return;
        }
        $payload = (array) $d->payload;
        $contactId = data_get($payload, 'data.contact.id') ?? (str_starts_with($d->event, 'contact.') ? data_get($payload, 'data.id') : null);
        if (! is_string($contactId) || ! DB::table('contacts')->where('id', $contactId)->exists()) {
            return;
        }
        Timeline::record($contactId, $d->status === 'delivered' ? 'webhook.delivered' : 'webhook.failed', Timeline::SYSTEM, 'webhook_delivery', $d->id,
            ['event' => $d->event, 'response_status' => $d->response_status, 'attempts' => $d->attempts]);
    }

    private static function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $json = json_decode($value, true);
            if (is_array($json)) {
                return $json;
            }

            // Postgres text[] as stored: {a,b}
            return $value === '{}' ? [] : str_getcsv(trim($value, '{}'));
        }

        return $value;
    }

    /** In a savepoint: a failure here never aborts the transaction of the thing being recorded. */
    private static function safe(callable $fn): void
    {
        try {
            DB::transaction(fn () => $fn());
        } catch (Throwable $e) {
            Log::warning('Timeline hook failed.', ['error' => $e->getMessage()]);
        }
    }
}
