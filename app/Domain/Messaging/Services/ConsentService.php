<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Developer\Services\PublicPayload;
use App\Domain\Developer\Services\WebhookDispatcher;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;

/**
 * Opt-in / opt-out. STOP-style keywords are honoured automatically on inbound text; an
 * opted-out contact cannot be messaged until they send START (or a workspace records consent).
 */
final class ConsentService
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /**
     * Where a consent was recorded (consent_events.source). Older values stay valid: keyword (STOP /
     * START), in_chat_button (the Inbox consent question), chatbot (an Opt-in / Opt-out block),
     * agent (manual), api, integration, meta_preference (the customer's WhatsApp setting).
     */
    public const SOURCE_FLOW_STEP = 'flow_step';

    public const SOURCE_WEBSITE_FORM = 'website_form';

    public const SOURCE_AD = 'ad';

    public const SOURCE_QR_LINK = 'qr_link';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_LABELS = [
        'flow_step' => 'Flow step', 'chatbot' => 'Flow step', 'website_form' => 'Website form', 'ad' => 'Ad', 'qr_link' => 'QR code or link',
        'import' => 'Import', 'agent' => 'Manual', 'keyword' => 'Keyword', 'in_chat_button' => 'Consent question', 'api' => 'API',
        'integration' => 'Store checkout', 'meta_preference' => 'WhatsApp setting',
    ];

    /** Button ids of the in-chat consent request (Inbox → Ask for consent). */
    public const BUTTON_OPT_IN = 'consent_opt_in';

    public const BUTTON_OPT_OUT = 'consent_opt_out';

    /** "No thanks" on consent requests sent before the Unsubscribe button: still understood. */
    public const BUTTON_DECLINE = 'consent_decline';

    /**
     * What an inbound message says about consent, the way WhatsApp tools handle it:
     *  - a tap on our own consent request is read by its button id (never by its title);
     *  - a tap on any other button or list row is an answer to the chatbot (or an agent's question), so
     *    a "Subscribe" or "Yes" button in a flow reaches the flow and its Opt-in block, which sends the
     *    flow's own confirmation. Only an opt-out word is still honoured there: STOP can never be missed;
     *  - typed text and template quick replies are matched against the keyword lists, whole message only.
     *
     * @return 'opt_out'|'opt_in'|'decline'|null
     */
    public function intentFor(Message $message, ?string $text = null): ?string
    {
        if (! in_array($message->type, ['text', 'button', 'interactive'], true)) {
            return null;
        }
        $text ??= $message->body;
        $content = (array) ($message->content ?? []);
        $reply = $content['button_reply'] ?? $content['list_reply'] ?? null;
        if ($message->type === 'interactive' && is_array($reply) && isset($reply['id'])) {
            return match ((string) $reply['id']) {
                self::BUTTON_OPT_IN => 'opt_in',
                self::BUTTON_OPT_OUT => 'opt_out',
                self::BUTTON_DECLINE => 'decline',
                default => $this->keywordIntent($text) === 'opt_out' ? 'opt_out' : null,
            };
        }

        return $this->keywordIntent($text);
    }

    /**
     * The customer tapped "No thanks" on the consent request: kept in the ledger as an answer. Their
     * consent state does not change (declining marketing is not an opt-out of the conversation).
     */
    public function recordDecline(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null, ?string $wording = null): void
    {
        $contact->forceFill(['consent_requested_at' => null])->save();
        $this->record($contact, 'consent_declined', $source, $detail, $messageId, null, $wording);
        // "No" to the consent question: opted out of marketing (other messages still allowed).
        $this->setMarketingPreference($contact, true, $detail, $source, $wording, $messageId);
    }

    /**
     * When the contact's current opt-out was the customer's own doing, how ("replied STOP on 16 Nov");
     * null when the team or an integration opted them out.
     */
    public function customerOptOut(Contact $contact): ?string
    {
        $event = ConsentEvent::query()->where('contact_id', $contact->id)->where('action', 'opted_out')->latest('created_at')->latest('id')->first(); // ids are time-ordered (UUID v7)
        if ($event === null) {
            return null;
        }
        $how = match ($event->source) {
            'keyword' => 'replied '.mb_strtoupper(trim((string) $event->detail) ?: 'STOP'),
            'in_chat_button' => 'tapped Unsubscribe',
            'meta_preference' => 'turned off messages in WhatsApp',
            default => null,
        };

        return $how === null ? null : trim($how.' on '.($event->created_at?->format('j M Y') ?? ''));
    }

    /** The consent question was sent: recorded in the ledger, and the contact waits for an answer. */
    public function requested(Contact $contact, ?string $messageId, ?string $membershipId): void
    {
        $contact->forceFill(['consent_requested_at' => now()])->save();
        $this->record($contact, 'consent_requested', 'in_chat_button', null, $messageId, $membershipId);
    }

    /** @return 'opt_out'|'opt_in'|null */
    public function keywordIntent(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) preg_replace('/[\p{P}\p{S}\s]+/u', ' ', $text)));
        if ($normalized === '' || mb_strlen($normalized) > 24) {
            return null; // only whole-message keywords, never "please don't stop sending"
        }

        // The workspace's own keyword lists (Compliance → Keywords), over the platform defaults.
        $settings = ComplianceSettings::for($this->context->tenantOrNull());
        if (in_array($normalized, $settings->optOutKeywords, true)) {
            return 'opt_out';
        }
        if (in_array($normalized, $settings->optInKeywords, true)) {
            return 'opt_in';
        }

        return null;
    }

    /** @return bool whether the state changed (false = the contact had already opted out) */
    public function optOut(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null, ?string $membershipId = null, ?string $wording = null): bool
    {
        if ($contact->consent_state === ConsentState::OptedOut) {
            return false;
        }

        $contact->forceFill(['consent_state' => ConsentState::OptedOut, 'opted_out_at' => now(), 'consent_requested_at' => null])->save();
        $this->record($contact, 'opted_out', $source, $detail, $messageId, $membershipId, $wording);
        app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.opted_out', PublicPayload::contact($contact) + ['source' => $source]);

        return true;
    }

    /** @return bool whether the state changed (false = the contact had already opted in) */
    public function optIn(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null, ?string $membershipId = null, ?string $wording = null): bool
    {
        if ($contact->consent_state === ConsentState::OptedIn) {
            return false;
        }

        $contact->forceFill(['consent_state' => ConsentState::OptedIn, 'opted_in_at' => now(), 'opted_out_at' => null, 'consent_requested_at' => null])->save();
        $this->record($contact, 'opted_in', $source, $detail, $messageId, $membershipId, $wording);
        app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.opted_in', PublicPayload::contact($contact) + ['source' => $source]);

        return true;
    }

    /** Meta user_preferences webhook (or a chatbot Opt-out block): the user stopped / resumed marketing messages. */
    public function setMarketingPreference(Contact $contact, bool $optedOut, ?string $detail = null, string $source = 'meta_preference', ?string $wording = null, ?string $messageId = null): void
    {
        if ($contact->marketing_opted_out === $optedOut) {
            return;
        }

        $contact->forceFill(['marketing_opted_out' => $optedOut])->save();
        $this->record($contact, $optedOut ? 'marketing_opted_out' : 'marketing_opted_in', $source, $detail, $messageId, null, $wording);
    }

    /**
     * The contact's consent ledger, newest first: what was recorded, how (source and channel), the
     * exact words the customer was shown, and when.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Contact $contact, int $limit = 100): array
    {
        return ConsentEvent::query()->where('contact_id', $contact->id)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (ConsentEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'source' => $e->source,
                'source_label' => self::SOURCE_LABELS[$e->source] ?? ucfirst(str_replace('_', ' ', $e->source)),
                'channel' => $e->getAttribute('channel') ?? 'whatsapp',
                'detail' => $e->detail,
                'wording' => $e->getAttribute('wording'),
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all();
    }

    private function record(Contact $contact, string $action, string $source, ?string $detail, ?string $messageId, ?string $membershipId, ?string $wording = null): void
    {
        ConsentEvent::query()->create([
            'contact_id' => $contact->id,
            'action' => $action,
            'source' => $source,
            'detail' => $detail !== null ? mb_substr($detail, 0, 190) : null,
            'message_id' => $messageId,
            'membership_id' => $membershipId,
            'channel' => 'whatsapp',
            'wording' => $wording !== null && trim($wording) !== '' ? mb_substr($wording, 0, 2000) : null,
        ]);

        $this->audit->record("contact.{$action}", $contact, meta: ['source' => $source]);
    }
}
