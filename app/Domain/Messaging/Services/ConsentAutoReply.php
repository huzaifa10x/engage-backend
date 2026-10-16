<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\Messaging\SendMessage;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Unsubscribed. Reply START to resume." / "You're subscribed." — sent inside the 24-hour window
 * the customer's own keyword just opened, or when a teammate records the customer's choice in the
 * inbox while a window is open (Compliance → "Confirm opt-in / opt-out" decides both). Recording
 * the consent never depends on this reply, and nothing is ever sent outside a window: a free-form
 * confirmation there would break WhatsApp's rules.
 */
final class ConsentAutoReply
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * A teammate recorded the customer's choice: confirm it to the customer, when allowed.
     *
     * @param  'opt_in'|'opt_out'  $intent
     * @return array{status: 'sent'|'disabled'|'window_closed'|'no_conversation'|'failed', message: string}
     */
    public function confirmRecorded(Contact $contact, string $intent, ?Conversation $conversation = null): array
    {
        $settings = ComplianceSettings::for($this->context->tenantOrNull());
        [$enabled, $text] = $intent === 'opt_out' ? [$settings->confirmOptOut, $settings->optOutReply] : [$settings->confirmOptIn, $settings->optInReply];
        if (! $enabled || trim($text) === '') {
            return ['status' => 'disabled', 'message' => 'No confirmation was sent: confirmations are turned off in Compliance.'];
        }

        // The conversation the teammate is in, or else the contact's most recent one with an open window.
        $conversation ??= Conversation::query()->where('contact_id', $contact->id)
            ->where('window_expires_at', '>', now())->orderByDesc('window_expires_at')->first();
        if ($conversation === null || $conversation->contact_id !== $contact->id) {
            $any = Conversation::query()->where('contact_id', $contact->id)->exists();

            return $any
                ? ['status' => 'window_closed', 'message' => 'No confirmation was sent: the customer has not written in the last 24 hours, and WhatsApp allows a free-form message only inside that window.']
                : ['status' => 'no_conversation', 'message' => 'No confirmation was sent: there is no WhatsApp conversation with this contact yet.'];
        }
        if (! $conversation->isWindowOpen()) {
            return ['status' => 'window_closed', 'message' => 'No confirmation was sent: the customer has not written in the last 24 hours, and WhatsApp allows a free-form message only inside that window.'];
        }

        try {
            app(SendMessage::class)->toConversation($conversation, ['type' => 'text', 'body' => $text, 'content' => ['preview_url' => false]],
                MessageOrigin::Automation, null, "consent-recorded:{$contact->id}:{$intent}:".now()->format('YmdHisv'), consentNotice: true);
        } catch (Throwable $e) {
            Log::warning('Consent confirmation could not be sent.', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'message' => 'The consent was recorded, but the confirmation could not be sent: '.$e->getMessage()];
        }

        return ['status' => 'sent', 'message' => 'The confirmation was sent to the customer.'];
    }

    /** @param 'opt_in'|'opt_out' $intent */
    public function confirm(Message $inbound, string $intent): void
    {
        $settings = ComplianceSettings::for($this->context->tenantOrNull());
        [$enabled, $text] = $intent === 'opt_out' ? [$settings->confirmOptOut, $settings->optOutReply] : [$settings->confirmOptIn, $settings->optInReply];
        if (! $enabled || trim($text) === '') {
            return;
        }

        try {
            $conversation = Conversation::query()->find($inbound->conversation_id);
            if ($conversation !== null) {
                app(SendMessage::class)->toConversation($conversation, ['type' => 'text', 'body' => $text, 'content' => ['preview_url' => false]],
                    MessageOrigin::Automation, null, "consent-reply:{$inbound->id}", consentNotice: true);
            }
        } catch (Throwable $e) {
            Log::warning('Consent confirmation could not be sent.', ['message_id' => $inbound->id, 'error' => $e->getMessage()]);
        }
    }
}
