<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Jobs;

use App\Domain\Conversions\Services\ConversionRecorder;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/** Records one conversion found by ConversionTriggers (maintenance queue: never in the way of messaging). */
final class RecordConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $triggerType,
        public readonly ?string $triggerRef,
        public readonly ?string $conversationId = null,
        public readonly ?string $contactId = null,
        public readonly ?string $at = null,
        public readonly ?string $orderId = null,
    ) {
        $this->onQueue(QueueName::Maintenance->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ConversionRecorder $recorder): void
    {
        $recorder->record($this->triggerType, $this->triggerRef, $this->conversationId, $this->contactId, $this->at !== null ? Carbon::parse($this->at) : now(), $this->orderId);
    }
}
