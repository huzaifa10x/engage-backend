<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

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

    public function __construct(public readonly Message $message, public readonly bool $created) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->message->tenant_id}.number.{$this->message->phone_number_id}")];
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
