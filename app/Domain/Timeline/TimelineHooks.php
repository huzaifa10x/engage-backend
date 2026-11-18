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
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Jobs\RecordTimeline;
use DateTimeInterface;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the timeline is written from: model events of the records that already exist (messages,
 * contacts, conversations, consent and stage changes, flow runs, store orders, webhook deliveries),
 * so every way a thing can happen — inbox, flows, API, imports, integrations — is covered once.
 *
 * Two steps, so recording never slows a reply down:
 *  1. capture, at the moment it happens: only what the model already holds (what changed, who, when),
 *     no queries. It is kept only once the change is committed (a rolled-back change records nothing);
 *  2. apply, in the background (RecordTimeline, maintenance queue): enquiries, names, the events.
 *
 * Changes to many contacts at once run inside Timeline::bulk(), so each contact's event says so.
 * Deleting a tag removes it with one query; CrmController records each contact's tag.removed itself.
 */
final class TimelineHooks
{
    /** Consent recorded from the customer's own action. */
    private const CUSTOMER_CONSENT = ['keyword', 'in_chat_button', 'website_form', 'qr_link', 'whatsapp_form', 'flow_step', 'chatbot', 'meta_preference', 'ad'];

    /** Flow events worth a line in the history (the run log has the rest). */
    private const FLOW_KINDS = ['enter' => 'flow.step', 'click' => 'flow.answer', 'complete' => 'flow.ended', 'handoff' => 'flow.ended', 'abandon' => 'flow.ended', 'error' => 'flow.step'];

    /** Off only to measure what recording costs. */
    public static bool $enabled = true;

    /** Time spent capturing since the last reset, in seconds (measurement only). */
    public static float $spent = 0.0;

    /** @var list<array<string, mixed>> captured and committed, waiting to be sent to the queue */
    private static array $ready = [];

    private static int $seq = 0;

    public static function register(): void
    {
        Message::created(fn (Message $m) => self::safe(fn () => self::message($m)));
        Contact::created(fn (Contact $c) => self::safe(fn () => self::capture('contact.created', [
            'contact_id' => $c->id, 'source' => $c->source, 'source_detail' => $c->getAttribute('source_detail'),
            'tags' => array_values(array_unique(array_map('strval', (array) $c->tags))), 'at' => self::iso($c->created_at),
        ])));
        Contact::updated(fn (Contact $c) => self::safe(fn () => self::tags($c)));
        Conversation::updated(fn (Conversation $c) => self::safe(fn () => self::conversation($c)));
        ConsentEvent::created(fn (ConsentEvent $e) => self::safe(fn () => self::capture('consent', [
            'contact_id' => (string) $e->contact_id, 'id' => $e->id, 'action' => $e->action, 'source' => (string) $e->source, 'detail' => $e->detail,
            'membership_id' => $e->getAttribute('membership_id'), 'at' => self::iso($e->created_at),
        ])));
        ContactStageEvent::created(fn (ContactStageEvent $e) => self::safe(fn () => self::capture('stage', [
            'contact_id' => $e->contact_id, 'id' => $e->id, 'from' => $e->from_stage_id, 'to' => $e->to_stage_id, 'reason' => $e->source,
            'note' => $e->reason, 'version' => $e->version, 'enquiry_id' => $e->enquiry_id,
            'membership_id' => $e->changed_by_membership_id, 'at' => self::iso($e->changed_at),
        ])));
        ChatbotEvent::created(fn (ChatbotEvent $e) => self::safe(fn () => self::flow($e)));
        CommerceOrder::saved(fn (CommerceOrder $o) => self::safe(fn () => self::order($o)));
        WebhookDelivery::updated(fn (WebhookDelivery $d) => self::safe(fn () => self::delivery($d)));

        // Captured events go to the queue once the outermost transaction has committed.
        Event::listen(TransactionCommitted::class, function (): void {
            if (self::$ready !== [] && ! self::inTransaction()) {
                self::flush();
            }
        });
    }

    // ── 1. Capture (at the moment it happens; no queries) ───────────────────────────────────

    private static function message(Message $m): void
    {
        $origin = $m->origin;
        if ($origin === MessageOrigin::History) {
            return; // imported chat history is old news: it becomes "Imported" enquiries, not live events
        }
        // Linked to the message itself (its ID and chat), so the line opens the exact message.
        $data = ['type' => $m->type, 'origin' => $origin->value, 'message_id' => $m->id, 'conversation_id' => $m->conversation_id];
        if ($m->template !== null) {
            $data['template'] = $m->template['name'] ?? null;
        }
        if ($m->getAttribute('campaign_id') !== null) {
            $data['campaign_id'] = $m->getAttribute('campaign_id');
        }
        $inbound = $m->direction === Message::INBOUND;
        $agent = ! $inbound && in_array($origin, [MessageOrigin::Agent, MessageOrigin::AppEcho], true) && $m->sent_by_membership_id !== null;
        self::capture('message', [
            'contact_id' => $m->contact_id, 'id' => $m->id, 'inbound' => $inbound, 'customer' => $origin === MessageOrigin::Customer,
            'agent' => $agent ? $m->sent_by_membership_id : null, 'touches' => $origin === MessageOrigin::Agent || $origin === MessageOrigin::Api,
            'data' => $data, 'at' => self::iso($m->getAttribute('meta_timestamp') ?? $m->created_at),
        ]);
    }

    private static function tags(Contact $c): void
    {
        if (! $c->wasChanged('tags')) {
            return;
        }
        $before = array_map('strval', (array) self::decode($c->getOriginal('tags')));
        $after = array_map('strval', (array) $c->tags);
        $lowerBefore = array_map('mb_strtolower', $before);
        $lowerAfter = array_map('mb_strtolower', $after);
        $added = array_values(array_filter(array_diff($after, $before), fn ($t) => ! in_array(mb_strtolower($t), $lowerBefore, true)));
        $removed = array_values(array_filter(array_diff($before, $after), fn ($t) => ! in_array(mb_strtolower($t), $lowerAfter, true)));
        if ($added !== [] || $removed !== []) {
            self::capture('tags', ['contact_id' => $c->id, 'added' => $added, 'removed' => $removed]);
        }
    }

    private static function conversation(Conversation $c): void
    {
        if ($c->wasChanged('assigned_membership_id')) {
            self::capture('assigned', ['contact_id' => $c->contact_id, 'conversation_id' => $c->id, 'to' => $c->assigned_membership_id]);
        }
        if ($c->wasChanged('status')) {
            self::capture('status', ['contact_id' => $c->contact_id, 'conversation_id' => $c->id, 'closed' => $c->status->value === 'closed']);
        }
    }

    private static function flow(ChatbotEvent $e): void
    {
        if (isset(self::FLOW_KINDS[$e->kind])) {
            // The contact is looked up with the run in the background; the capture needs a key to order by.
            self::capture('flow', ['contact_id' => 'session:'.$e->session_id, 'session_id' => $e->session_id, 'kind' => $e->kind, 'step' => $e->node_id,
                'detail' => $e->detail, 'at' => self::iso($e->created_at)]);
        }
    }

    private static function order(CommerceOrder $o): void
    {
        if ($o->contact_id === null) {
            return;
        }
        $paidNow = $o->paid && ($o->wasRecentlyCreated || $o->wasChanged('paid'));
        $refunded = $o->wasChanged('refunded') && (float) $o->refunded > (float) $o->getOriginal('refunded');
        if (! $paidNow && ! $refunded && $o->getAttribute('enquiry_id') !== null) {
            return;
        }
        self::capture('order', [
            'contact_id' => $o->contact_id, 'id' => $o->id, 'enquiry_id' => $o->getAttribute('enquiry_id'), 'paid' => $paidNow, 'refunded' => $refunded,
            'data' => ['order' => $o->number, 'store' => $o->store_name, 'total' => (float) $o->total, 'currency' => $o->currency, 'status' => $o->status],
            'refunded_amount' => (float) $o->refunded, 'paid_at' => self::iso($o->paid_at),
        ]);
    }

    private static function delivery(WebhookDelivery $d): void
    {
        if (! $d->wasChanged('status') || ! in_array($d->status, ['delivered', 'failed'], true)) {
            return;
        }
        $payload = (array) $d->payload;
        $contactId = data_get($payload, 'data.contact.id') ?? (str_starts_with($d->event, 'contact.') ? data_get($payload, 'data.id') : null);
        if (is_string($contactId)) {
            self::capture('delivery', ['contact_id' => $contactId, 'id' => $d->id, 'status' => $d->status, 'event' => $d->event,
                'response_status' => $d->response_status, 'attempts' => $d->attempts]);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function capture(string $op, array $payload): void
    {
        $tenantId = app(TenantContext::class)->tenantOrNull()?->id;
        if ($tenantId === null || ! is_string($payload['contact_id'] ?? null)) {
            return;
        }
        $entry = ['op' => $op, 'payload' => $payload, 'seq' => ++self::$seq, 'tenant_id' => $tenantId, 'moment' => Timeline::moment()];
        if (! self::inTransaction()) {
            self::$ready[] = $entry;
            self::flush();

            return;
        }
        // Kept only if the change commits: a rolled-back transaction drops its callbacks.
        DB::afterCommit(function () use ($entry): void {
            self::$ready[] = $entry;
        });
    }

    /** Sends what is ready to the queue: one job per workspace, in the order things happened. */
    public static function flush(): void
    {
        $ready = self::$ready;
        self::$ready = [];
        usort($ready, fn ($a, $b) => $a['seq'] <=> $b['seq']);
        $byTenant = [];
        foreach ($ready as $entry) {
            $byTenant[$entry['tenant_id']][] = $entry;
        }
        foreach ($byTenant as $tenantId => $entries) {
            foreach (array_chunk($entries, 500) as $chunk) {
                RecordTimeline::dispatch((string) $tenantId, $chunk);
            }
        }
    }

    private static function inTransaction(): bool
    {
        return app('db.transactions')->callbackApplicableTransactions()->isNotEmpty();
    }

    // ── 2. Apply (in the background, in the workspace) ───────────────────────────────────────

    /** @param array<string, mixed> $p */
    public static function apply(string $op, array $p): void
    {
        match ($op) {
            'message' => self::applyMessage($p),
            'contact.created' => self::applyCreated($p),
            'tags' => self::applyTags($p),
            'assigned' => self::applyAssigned($p),
            'status' => Timeline::record($p['contact_id'], $p['closed'] ? 'conversation.closed' : 'conversation.reopened', $p['closed'] ? null : Timeline::SYSTEM,
                'conversation', $p['conversation_id']),
            'consent' => self::applyConsent($p),
            'stage' => self::applyStage($p),
            'flow' => self::applyFlow($p),
            'order' => self::applyOrder($p),
            'delivery' => self::applyDelivery($p),
            default => null,
        };
    }

    /** @param array<string, mixed> $p */
    private static function applyMessage(array $p): void
    {
        if ($p['inbound']) {
            $enquiryId = null;
            if ($p['customer']) {
                $message = Message::query()->find($p['id']);
                /** @var ?Contact $contact */
                $contact = Contact::query()->withTrashed()->find($p['contact_id']);
                if ($message !== null && $contact !== null) {
                    $enquiryId = app(Enquiries::class)->forInbound($message, $contact)->id;
                }
            }
            Timeline::record($p['contact_id'], 'message.received', Timeline::CUSTOMER, 'message', $p['id'], $p['data'], $p['at'], $enquiryId);

            return;
        }
        Timeline::record($p['contact_id'], 'message.sent', $p['agent'] !== null ? Timeline::AGENT : Timeline::SYSTEM, 'message', $p['id'], $p['data'], $p['at'],
            null, $p['agent']);
        if ($p['touches']) {
            DB::table('enquiries')->where('id', self::currentEnquiry($p['contact_id']))->update(['last_activity_at' => $p['at'] ?? now()]);
        }
    }

    /** @param array<string, mixed> $p */
    private static function applyCreated(array $p): void
    {
        Timeline::record($p['contact_id'], 'contact.created', null, 'contact', $p['contact_id'], ['source' => $p['source'], 'source_detail' => $p['source_detail']], $p['at'], null);
        // Tags it was created with (an import, the API, a store): each is a change worth seeing.
        foreach ($p['tags'] as $tag) {
            Timeline::record($p['contact_id'], 'tag.added', null, 'contact', $p['contact_id'], ['tag' => $tag], $p['at'], null);
        }
    }

    /** @param array<string, mixed> $p */
    private static function applyTags(array $p): void
    {
        foreach ($p['added'] as $tag) {
            Timeline::record($p['contact_id'], 'tag.added', null, 'contact', $p['contact_id'], ['tag' => $tag]);
        }
        foreach ($p['removed'] as $tag) {
            Timeline::record($p['contact_id'], 'tag.removed', null, 'contact', $p['contact_id'], ['tag' => $tag]);
        }
    }

    /** @param array<string, mixed> $p */
    private static function applyAssigned(array $p): void
    {
        $to = $p['to'];
        $name = $to !== null ? DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->where('tenant_memberships.id', $to)->value('users.name') : null;
        Timeline::record($p['contact_id'], 'conversation.assigned', null, 'conversation', $p['conversation_id'], ['to_membership_id' => $to, 'to_name' => $name]);
        // The enquiry's owner follows who has the chat.
        DB::table('enquiries')->where('id', self::currentEnquiry($p['contact_id']))->update(['owner_membership_id' => $to]);
    }

    /** @param array<string, mixed> $p */
    private static function applyConsent(array $p): void
    {
        $source = $p['source'];
        $actor = in_array($source, self::CUSTOMER_CONSENT, true) ? Timeline::CUSTOMER : ($source === 'agent' ? Timeline::AGENT : Timeline::SYSTEM);
        Timeline::record($p['contact_id'], 'consent.changed', $actor, 'consent_event', $p['id'], [
            'action' => $p['action'], 'source' => $source, 'source_label' => ConsentService::SOURCE_LABELS[$source] ?? $source, 'detail' => $p['detail'],
        ], $p['at'], null, $actor === Timeline::AGENT ? $p['membership_id'] : null);
    }

    /** @param array<string, mixed> $p */
    private static function applyStage(array $p): void
    {
        $stages = CrmStage::query()->whereIn('id', array_filter([$p['from'], $p['to']]))->get(['id', 'name', 'group'])->keyBy('id');
        $names = $stages->map(fn (CrmStage $s) => $s->name);
        $actor = $p['membership_id'] !== null ? Timeline::AGENT : Timeline::SYSTEM;
        Timeline::record($p['contact_id'], 'stage.changed', $actor, 'stage_event', $p['id'], [
            'from' => $p['from'] !== null ? ($names[$p['from']] ?? null) : null,
            'to' => $p['to'] !== null ? ($names[$p['to']] ?? null) : null,
            'reason' => $p['reason'],
            'note' => $p['note'] ?? null,
            'version' => $p['version'] ?? null,
            'to_group' => $p['to'] !== null ? ($stages[$p['to']]->group ?? null) : null,
        ], $p['at'], $p['enquiry_id'] ?? null, $p['membership_id']);
        // Stages written before item 4 lived on the contact: the enquiry follows it.
        if (($p['enquiry_id'] ?? null) === null) {
            DB::table('enquiries')->where('id', self::currentEnquiry($p['contact_id']))->update(['stage_id' => $p['to']]);
        }
    }

    /** @param array<string, mixed> $p */
    private static function applyFlow(array $p): void
    {
        $session = ChatbotSession::query()->find($p['session_id'], ['id', 'contact_id', 'flow_id']);
        if ($session === null) {
            return;
        }
        $type = self::FLOW_KINDS[$p['kind']];
        $flow = DB::table('chatbot_flows')->where('id', $session->flow_id)->value('name');
        // A tap or answer is the customer's; steps the flow takes are the system's.
        Timeline::record((string) $session->contact_id, $type, $type === 'flow.answer' ? Timeline::CUSTOMER : Timeline::SYSTEM, 'flow_session', $session->id,
            array_filter(['flow' => $flow, 'step' => $p['step'], 'kind' => $p['kind'], 'detail' => $p['detail']]), $p['at']);
    }

    /** @param array<string, mixed> $p */
    private static function applyOrder(array $p): void
    {
        // A store order belongs to the enquiry that was current when it was first linked to the contact.
        $enquiry = $p['enquiry_id'] ?? DB::table('commerce_orders')->where('id', $p['id'])->value('enquiry_id');
        if ($enquiry === null) {
            $enquiry = self::currentEnquiry($p['contact_id']);
            if ($enquiry !== null) {
                DB::table('commerce_orders')->where('id', $p['id'])->whereNull('enquiry_id')->update(['enquiry_id' => $enquiry]);
            }
        }
        if ($p['paid']) {
            Timeline::record($p['contact_id'], 'payment.paid', Timeline::SYSTEM, 'order', $p['id'], $p['data'] + ['verified_by' => 'store'], $p['paid_at'], $enquiry);
        }
        if ($p['refunded']) {
            Timeline::record($p['contact_id'], 'payment.refunded', Timeline::SYSTEM, 'order', $p['id'], $p['data'] + ['refunded' => $p['refunded_amount']], null, $enquiry);
        }
    }

    /** @param array<string, mixed> $p */
    private static function applyDelivery(array $p): void
    {
        if (! DB::table('contacts')->where('id', $p['contact_id'])->exists()) {
            return;
        }
        Timeline::record($p['contact_id'], $p['status'] === 'delivered' ? 'webhook.delivered' : 'webhook.failed', Timeline::SYSTEM, 'webhook_delivery', $p['id'],
            ['event' => $p['event'], 'response_status' => $p['response_status'], 'attempts' => $p['attempts']]);
    }

    private static function currentEnquiry(string $contactId): ?string
    {
        $id = DB::table('contacts')->where('id', $contactId)->value('current_enquiry_id');

        return is_string($id) ? $id : null;
    }

    private static function iso(mixed $at): ?string
    {
        if ($at instanceof DateTimeInterface) {
            return Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s.uP');
        }

        return is_string($at) ? $at : null;
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

    /** Capturing never breaks what it records. */
    private static function safe(callable $fn): void
    {
        if (! self::$enabled) {
            return;
        }
        $start = hrtime(true);
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('Timeline capture failed.', ['error' => $e->getMessage()]);
        } finally {
            self::$spent += (hrtime(true) - $start) / 1e9;
        }
    }
}
