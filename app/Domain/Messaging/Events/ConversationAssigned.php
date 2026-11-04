<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat changed hands. Sent to the number's channel (Owners and Admins, who see every chat) and to
 * the previous and the new assignee's own channels, so an agent's list gains or loses the chat at
 * once. Ids only; clients refetch.
 */
final class ConversationAssigned implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $queue = 'notifications';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $phoneNumberId,
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("tenant.{$this->tenantId}.number.{$this->phoneNumberId}")];
        foreach (array_unique(array_filter([$this->from, $this->to])) as $member) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.member.{$member}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.assigned';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'from' => $this->from, 'to' => $this->to];
    }
}
