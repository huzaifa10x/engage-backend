<?php

declare(strict_types=1);

namespace App\Domain\Growth\Jobs;

use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Services\EvidenceRecorder;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background attribution for one inbound message, after it is saved and the chatbot has it.
 * Lower priority than webhooks and sending (default queue). A failure here is logged and never
 * touches the conversation: attribution errors never block a chat. Runs in the message's
 * workspace (the context travels with the job).
 */
final class RecordMessageEvidence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TenantContext $context, EvidenceRecorder $recorder): void
    {
        $tenant = $context->tenantOrNull();
        $message = Message::query()->find($this->messageId);
        if ($tenant === null || $message === null || ! GrowthAccess::recording($tenant)) {
            return;
        }

        $recorder->recordMetaReferral($message, $tenant);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Growth Hub could not record evidence for a message.', ['message_id' => $this->messageId, 'error' => $e?->getMessage()]);
    }
}
