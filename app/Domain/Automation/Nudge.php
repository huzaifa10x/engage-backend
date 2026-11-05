<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Application\Messaging\SendMessage;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Customer-side follow-up (Engagement Plan §IV.2): the customer went quiet after a person's reply.
 * One normal text message while the 24 h window is open (a reply in a conversation the customer
 * started: no marketing opt-in needed). Stops when the customer writes, opts out, a person takes
 * the chat over or writes again. Held over quiet hours; skipped when the window would close first.
 */
final class Nudge extends ActionHandler
{
    public function __construct(private readonly SendMessage $send) {}

    public function needsConsent(): bool
    {
        return true;
    }

    public function staysOutOfPersonsWay(): bool
    {
        return true;
    }

    public function cancelsOnReply(): bool
    {
        return true;
    }

    public function respectsQuietHours(): bool
    {
        return true;
    }

    public function mayWaitUntil(Conversation $conversation, Carbon $next): bool
    {
        return $conversation->window_expires_at !== null && $conversation->window_expires_at->gt($next->copy()->addMinutes(5));
    }

    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        if ($conversation === null || $contact === null || ReplyState::shown($conversation) !== ReplyState::WAITING) {
            return 'cancelled:not_waiting';
        }
        if (! $conversation->isWindowOpen()) {
            return 'skipped:window_closed';
        }
        $s = FollowUps::settings(app(TenantContext::class)->tenantOrNull());
        $first = trim((string) strtok($contact->displayName(), ' '));
        $text = trim(str_replace(['{{name}}', '{{first_name}}'], $first !== '' ? $first : 'there', $s['nudge']['message']));
        if ($text === '') {
            return 'skipped:no_message';
        }
        $this->send->toConversation($conversation, ['type' => 'text', 'body' => $text, 'content' => ['preview_url' => false]], MessageOrigin::Automation, null, 'nudge:'.($payload['after_message'] ?? $conversation->id));

        // The optional second nudge: an approved template later, for opted-in contacts only.
        if ($s['second_nudge']['enabled'] && FollowUps::allowed(app(TenantContext::class)->tenant())['nudges']) {
            app(Scheduler::class)->schedule('nudge_template', now()->addHours($s['second_nudge']['hours']), "nudge_template:{$conversation->id}:".($payload['after_message'] ?? ''),
                $conversation, payload: ['after_message' => $payload['after_message'] ?? null, 'cancel_on_reply' => true], priority: 20, sendsMessage: true);
        }

        return 'done:sent';
    }
}
