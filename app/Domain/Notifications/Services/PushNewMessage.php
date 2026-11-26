<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

/**
 * A customer wrote: the phone of whoever should answer buzzes. Assigned chats go to the assignee
 * only; a chat nobody has taken goes to the people who see unassigned chats on that number. Not
 * while a chatbot is answering. One notification per chat per 20 seconds (a burst of messages
 * replaces it on the phone rather than piling up).
 */
final class PushNewMessage implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly PushNotifier $push, private readonly NumberAccess $access) {}

    public function handle(MessageStored $stored): void
    {
        $message = $stored->message;
        if (! $stored->created || $message->direction !== Message::INBOUND || $message->origin !== MessageOrigin::Customer || $message->type === 'reaction') {
            return;
        }
        $conversation = Conversation::query()->with('contact')->find($message->conversation_id);
        if ($conversation === null || $conversation->getAttribute('handler') === 'bot' || ($conversation->getAttribute('snoozed_until') instanceof \DateTimeInterface && $conversation->getAttribute('snoozed_until') > now())) {
            return;
        }
        $number = PhoneNumber::query()->find($conversation->phone_number_id);
        if ($number === null) {
            return;
        }

        $recipients = $conversation->assigned_membership_id !== null
            ? TenantMembership::query()->with('role')->whereKey($conversation->assigned_membership_id)->get()
            : TenantMembership::query()->with('role')->get()->filter(fn (TenantMembership $m) => $m->isActive() && $this->access->seesAllChats($m) && $this->access->canAccess($m, $number));

        $name = $conversation->contact?->displayName() ?? 'WhatsApp user';
        $isNew = $conversation->status === ConversationStatus::Open && $conversation->assigned_membership_id === null;
        foreach ($recipients as $member) {
            if (! Cache::add("push-message:{$member->id}:{$conversation->id}", 1, 20)) {
                continue;
            }
            $this->push->toMember($member, 'message', $isNew ? "{$name} · new chat" : $name, $message->preview(), "/inbox?c={$conversation->id}", "chat-{$conversation->id}");
        }
    }
}
