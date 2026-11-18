<?php

declare(strict_types=1);

namespace App\Domain\Developer\Services;

use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Outgoing webhooks read from the contact timeline (batch 6, item 5): each timeline event of a type
 * below becomes the webhook event next to it, so there is one list of what happened, not two.
 * Message events (received, sent, delivered, read, failed) and contact.updated still come from
 * where they happen; they are not timeline types. Booking events join with the booking engine (item 2).
 *
 * Every event says when it happened (UTC) and in which time zone the workspace works, which enquiry
 * it belongs to, and who caused it: the customer, a teammate (agent) or the system.
 */
final class TimelineWebhooks
{
    /** Timeline type => webhook event, with the sentence shown when choosing events. */
    public const EVENTS = [
        'contact.created' => ['contact.created', 'A contact was added'],
        'tag.added' => ['contact.tag_added', 'A tag was added to a contact'],
        'tag.removed' => ['contact.tag_removed', 'A tag was removed from a contact'],
        'consent.changed' => ['contact.consent_changed', 'A contact’s consent changed'],
        'enquiry.started' => ['enquiry.created', 'A new enquiry started'],
        'stage.changed' => ['enquiry.stage_changed', 'An enquiry moved to another stage'],
        'payment.paid' => ['payment.paid', 'A payment was received'],
        'payment.refunded' => ['payment.refunded', 'A payment was refunded'],
        'conversation.assigned' => ['conversation.assigned', 'A conversation was assigned to a teammate'],
        'conversation.closed' => ['conversation.closed', 'A conversation was closed'],
        'form.submitted' => ['form.submitted', 'A WhatsApp form was submitted'],
    ];

    /** Consent changes are also sent under the older names, so existing receivers keep working. */
    private const CONSENT = ['opted_in' => 'contact.opted_in', 'opted_out' => 'contact.opted_out'];

    private static bool $paused = false;

    public function __construct(private readonly WebhookDispatcher $webhooks, private readonly TenantContext $context) {}

    /** @return array<string, string> webhook event => sentence */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::EVENTS as [$event, $label]) {
            $out[$event] = $label;
        }

        return $out + ['contact.opted_in' => 'A contact opted in', 'contact.opted_out' => 'A contact opted out'];
    }

    /**
     * Records without sending webhooks: rebuilding history (a backfill) is not news to a receiver.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function paused(callable $fn): mixed
    {
        $outer = self::$paused;
        self::$paused = true;
        try {
            return $fn();
        } finally {
            self::$paused = $outer;
        }
    }

    /**
     * Called by Timeline::record once the event is stored.
     *
     * @param  array{id: string, tenant_id: string, contact_id: string, enquiry_id: ?string, type: string, occurred_at: Carbon, actor: string, actor_name: ?string, subject_type: ?string, subject_id: ?string, data: array<string, mixed>}  $event
     */
    public function recorded(array $event): void
    {
        $map = self::EVENTS[$event['type']] ?? null;
        if ($map === null || self::$paused || ! $this->anyEndpoint()) {
            return;
        }
        $data = $event['data'];
        if ($event['type'] === 'conversation.assigned' && ($data['to_membership_id'] ?? null) === null) {
            return; // unassigned: not an assignment
        }

        $contact = Contact::query()->withTrashed()->find($event['contact_id']);
        $payload = $this->subject($event, $contact);
        $meta = [
            'occurred_at' => $event['occurred_at']->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $this->context->tenant()->timezone,
            'enquiry_id' => $event['enquiry_id'],
            'actor' => $event['actor'],
            'actor_name' => $event['actor_name'],
            'timeline_event_id' => $event['id'],
        ];

        $this->webhooks->emit($event['tenant_id'], $map[0], $payload, $event['id'], $meta);
        if ($event['type'] === 'consent.changed' && isset(self::CONSENT[$data['action'] ?? ''])) {
            $this->webhooks->emit($event['tenant_id'], self::CONSENT[$data['action']], $payload, $event['id'], $meta);
        }
    }

    /**
     * What the event is about, as documented in the API reference.
     *
     * @param  array{type: string, subject_id: ?string, data: array<string, mixed>}  $event
     * @return array<string, mixed>
     */
    private function subject(array $event, ?Contact $contact): array
    {
        $person = $contact !== null ? PublicPayload::contact($contact) : null;
        $data = $event['data'];

        return match ($event['type']) {
            'contact.created' => ($person ?? []) + ['source' => $data['source'] ?? null],
            'tag.added', 'tag.removed' => ['tag' => $data['tag'] ?? null, 'contact' => $person],
            'consent.changed' => ($person ?? []) + ['action' => $data['action'] ?? null, 'source' => $data['source'] ?? null],
            'conversation.assigned', 'conversation.closed' => ($c = Conversation::query()->with('contact')->find($event['subject_id'])) !== null
                ? PublicPayload::conversation($c)
                : ['id' => $event['subject_id'], 'contact' => $person],
            default => $data + ['contact' => $person],
        };
    }

    /** Most workspaces have no endpoint: skip the work then. */
    private function anyEndpoint(): bool
    {
        return WebhookEndpoint::query()->where('status', 'active')->exists();
    }
}
