<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime inbox update: a message was created or changed (status, edit, revoke, media ready).
 * Channel is per workspace × number, so per-number access is enforced at subscription time
 * (routes/channels.php). Payload is intentionally small; clients refetch details by id.
 */
final class MessageStored implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'notifications';

    /** The chat's assignee when the event was raised (in the workspace's context). */
    public readonly ?string $assignee;

    public function __construct(public readonly Message $message, public readonly bool $created)
    {
        $assignee = Conversation::query()->whereKey($message->conversation_id)->value('assigned_membership_id');
        $this->assignee = is_string($assignee) ? $assignee : null;
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        // The number's channel is for people who see every chat on it (Owners, Admins); the assignee
        // gets the event on their own channel. Agents never receive other people's chats.
        $channels = [new PrivateChannel("tenant.{$this->message->tenant_id}.number.{$this->message->phone_number_id}")];
        if ($this->assignee !== null) {
            $channels[] = new PrivateChannel("tenant.{$this->message->tenant_id}.member.{$this->assignee}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return $this->created ? 'message.created' : 'message.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'direction' => $this->message->direction,
            'type' => $this->message->type,
            'status' => $this->message->status->value,
            'preview' => $this->message->preview(),
        ];
    }
}
