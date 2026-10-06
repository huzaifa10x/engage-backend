<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\Messaging\SendMessage;
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

    /** @return array{enabled: bool, message: string, cooldown_hours: int} */
    public static function settings(?Tenant $tenant): array
    {
        $stored = (array) (($tenant->settings ?? [])['auto_reply'] ?? []);

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'message' => (string) ($stored['message'] ?? 'Thanks for your message! Our team has received it and will reply as soon as possible.'),
            'cooldown_hours' => max(1, min(168, (int) ($stored['cooldown_hours'] ?? 24))),
        ];
    }

    public function handle(Message $inbound, Contact $contact): void
    {
        $settings = self::settings($this->context->tenantOrNull());
        if (! $settings['enabled'] || trim($settings['message']) === '' || $contact->isOptedOut()) {
            return;
        }
        // "STOP" / "START" already get their own confirmation.
        if (in_array($inbound->type, ['text', 'button', 'interactive'], true) && $this->consent->keywordIntent((string) $inbound->body) !== null) {
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
