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

    /** Button ids of the in-chat consent request (Inbox → Ask for consent). */
    public const BUTTON_OPT_IN = 'consent_opt_in';

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
    public function recordDecline(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null): void
    {
        $this->record($contact, 'consent_declined', $source, $detail, $messageId, null);
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
    public function optOut(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null, ?string $membershipId = null): bool
    {
        if ($contact->consent_state === ConsentState::OptedOut) {
            return false;
        }

        $contact->forceFill(['consent_state' => ConsentState::OptedOut, 'opted_out_at' => now()])->save();
        $this->record($contact, 'opted_out', $source, $detail, $messageId, $membershipId);
        app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.opted_out', PublicPayload::contact($contact) + ['source' => $source]);

        return true;
    }

    /** @return bool whether the state changed (false = the contact had already opted in) */
    public function optIn(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null, ?string $membershipId = null): bool
    {
        if ($contact->consent_state === ConsentState::OptedIn) {
            return false;
        }

        $contact->forceFill(['consent_state' => ConsentState::OptedIn, 'opted_in_at' => now(), 'opted_out_at' => null])->save();
        $this->record($contact, 'opted_in', $source, $detail, $messageId, $membershipId);
        app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.opted_in', PublicPayload::contact($contact) + ['source' => $source]);

        return true;
    }

    /** Meta user_preferences webhook (or a chatbot Opt-out block): the user stopped / resumed marketing messages. */
    public function setMarketingPreference(Contact $contact, bool $optedOut, ?string $detail = null, string $source = 'meta_preference'): void
    {
        if ($contact->marketing_opted_out === $optedOut) {
            return;
        }

        $contact->forceFill(['marketing_opted_out' => $optedOut])->save();
        $this->record($contact, $optedOut ? 'marketing_opted_out' : 'marketing_opted_in', $source, $detail, null, null);
    }

    private function record(Contact $contact, string $action, string $source, ?string $detail, ?string $messageId, ?string $membershipId): void
    {
        ConsentEvent::query()->create([
            'contact_id' => $contact->id,
            'action' => $action,
            'source' => $source,
            'detail' => $detail !== null ? mb_substr($detail, 0, 190) : null,
            'message_id' => $messageId,
            'membership_id' => $membershipId,
        ]);

        $this->audit->record("contact.{$action}", $contact, meta: ['source' => $source]);
    }
}
