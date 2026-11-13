<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\Messaging\SendMessage;
use App\Domain\Chatbots\Engine\BotInput;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The workspace's automatic reply to incoming messages ("Thanks, we'll get back to you soon").
 * Off by default; when on it answers at most once per conversation per cooldown period, never
 * answers an opt-out / opt-in keyword, and can be switched off for a single conversation.
 */
final class AutoReply
{
    public function __construct(private readonly TenantContext $context, private readonly ConsentService $consent) {}

    /**
     * reply_with: "message" (the text below) or "chatbot" (a chatbot flow per WhatsApp number answers
     * instead, started by the chatbot engine at the same moments and with the same once-per-period
     * limit).
     *
     * @return array{enabled: bool, message: string, cooldown_hours: int, when: string, reply_with: string, flows: array<string, string>}
     */
    public static function settings(?Tenant $tenant): array
    {
        $stored = (array) (($tenant->settings ?? [])['auto_reply'] ?? []);
        $flows = [];
        foreach ((array) ($stored['flows'] ?? []) as $numberId => $flowId) {
            if (is_string($numberId) && is_string($flowId) && $flowId !== '') {
                $flows[$numberId] = $flowId;
            }
        }

        return [
            'reply_with' => ($stored['reply_with'] ?? 'message') === 'chatbot' ? 'chatbot' : 'message',
            'flows' => $flows,
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'message' => (string) ($stored['message'] ?? 'Thanks for your message! Our team has received it and will reply as soon as possible.'),
            'cooldown_hours' => max(1, min(168, (int) ($stored['cooldown_hours'] ?? 24))),
            // always | outside_hours (only when the team is away, per Business hours)
            'when' => ($stored['when'] ?? 'always') === 'outside_hours' ? 'outside_hours' : 'always',
        ];
    }

    public function handle(Message $inbound, Contact $contact): void
    {
        $tenant = $this->context->tenantOrNull();
        $settings = self::settings($tenant);
        // A chatbot answers instead: the chatbot engine starts it (Chatbots::pick).
        if (! $settings['enabled'] || $settings['reply_with'] === 'chatbot' || trim($settings['message']) === '' || $contact->isOptedOut()) {
            return;
        }
        if ($settings['when'] === 'outside_hours' && InboxTools::isOpen($tenant, null, $inbound->getAttribute('phone_number_id'))) {
            return; // the team is at work: a person will answer
        }
        // "STOP" / "START" already get their own confirmation.
        if ($this->consent->intentFor($inbound, BotInput::textFor($inbound)) !== null) {
            return;
        }

        try {
            $conversation = Conversation::query()->find($inbound->conversation_id);
            if ($conversation === null || ! $conversation->getAttribute('auto_reply_enabled')) {
                return;
            }
            $last = $conversation->getAttribute('last_auto_reply_at');
            if ($last !== null && $last->gt(now()->subHours($settings['cooldown_hours']))) {
                return; // already answered recently: never a reply to every single message
            }

            // Claim first, so two messages arriving together cannot both trigger a reply.
            $claimed = Conversation::query()->whereKey($conversation->id)
                ->where(fn ($q) => $q->whereNull('last_auto_reply_at')->orWhere('last_auto_reply_at', '<=', now()->subHours($settings['cooldown_hours'])))
                ->update(['last_auto_reply_at' => now()]);
            if ($claimed !== 1) {
                return;
            }

            app(SendMessage::class)->toConversation($conversation, ['type' => 'text', 'body' => $settings['message'], 'content' => ['preview_url' => false]],
                MessageOrigin::Automation, null, "auto-reply:{$inbound->id}");
        } catch (Throwable $e) {
            Log::warning('Auto reply could not be sent.', ['message_id' => $inbound->id, 'error' => $e->getMessage()]);
        }
    }
}
