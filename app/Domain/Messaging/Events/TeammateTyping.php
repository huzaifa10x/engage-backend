<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A teammate is typing a reply in a chat (3.5, collision detection). Sent to the chat's presence channel,
 * where everyone who has the chat open is listed; the typist's own browser ignores it.
 */
final class TeammateTyping implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $membershipId,
        public readonly string $name,
        public readonly bool $typing,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("tenant.{$this->tenantId}.conversation.{$this->conversationId}");
    }

    public function broadcastAs(): string
    {
        return 'teammate.typing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['membership_id' => $this->membershipId, 'name' => $this->name, 'typing' => $this->typing];
    }
}
