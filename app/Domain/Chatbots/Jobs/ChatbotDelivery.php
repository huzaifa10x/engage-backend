<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Jobs;

use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Messaging\Models\Message;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** WhatsApp reported on a bot message: a template waiting for its delivery report continues. */
final class ChatbotDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue(QueueName::Messaging->value);
    }

    public function handle(Chatbots $chatbots): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message !== null) {
            $chatbots->delivery($message);
        }
    }
}
