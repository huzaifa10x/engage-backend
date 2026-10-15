<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Jobs;

use App\Domain\Chatbots\Services\Chatbots;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Lets the number's chatbot answer one customer message. Dispatched after the message is stored
 * (and therefore already in the inbox); runs one conversation at a time under a lock, so two
 * quick messages are answered in order and never twice.
 */
final class RunChatbot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue(QueueName::Messaging->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(Chatbots $chatbots): void
    {
        $chatbots->onInbound($this->messageId);
    }
}
