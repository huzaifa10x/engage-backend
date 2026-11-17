<?php

declare(strict_types=1);

namespace App\Domain\Growth\Links;

use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** In the background, after the chat and the chatbot have the message: a link's code on it is recorded. */
final class RecordLinkChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(TenantContext $context, LinkChats $chats): void
    {
        $tenant = $context->tenantOrNull();
        $message = Message::query()->find($this->messageId);
        if ($tenant !== null && $message !== null) {
            $chats->record($message, $tenant);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('A chat from a WhatsApp link could not be recorded.', ['message_id' => $this->messageId, 'error' => $e?->getMessage()]);
    }
}
