<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Jobs;

use App\Domain\Conversions\Services\AdClicks;
use App\Domain\Messaging\Models\Message;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Saves the ad click of one incoming message, after the message is in the inbox. Never touches the chat. */
final class RecordAdClick implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue(QueueName::Maintenance->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(AdClicks $clicks): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message !== null) {
            $clicks->record($message);
        }
    }
}
