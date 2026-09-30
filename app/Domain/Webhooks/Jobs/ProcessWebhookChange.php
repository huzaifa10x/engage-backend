<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Jobs;

use App\Domain\Webhooks\WebhookProcessor;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Carries only the log row key — payloads (history chunks) can be megabytes. */
final class ProcessWebhookChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $id, public readonly string $receivedAt)
    {
        $this->onQueue(QueueName::Webhooks->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->id, $this->receivedAt);
    }
}
