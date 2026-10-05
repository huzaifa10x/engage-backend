<?php

declare(strict_types=1);

namespace App\Domain\Templates\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime Templates page: a template of this workspace was created, changed status (approved,
 * rejected, paused …), was edited or deleted — whether here, by a Meta webhook or by a sync.
 * The payload is tiny on purpose; clients refetch the list.
 */
final class TemplatesChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'notifications';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $templateId,
        public readonly string $name,
        public readonly string $status,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.templates")];
    }

    public function broadcastAs(): string
    {
        return 'templates.changed';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['id' => $this->templateId, 'name' => $this->name, 'status' => $this->status];
    }
}
