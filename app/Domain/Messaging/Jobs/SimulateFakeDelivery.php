<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Services\StatusApplier;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * LOCAL DEVELOPMENT ONLY (fake Meta mode): plays the status webhooks Meta would send for a
 * message — sent, delivered, then read — through the real StatusApplier, so ticks and realtime
 * updates behave exactly like production.
 */
final class SimulateFakeDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public readonly string $wamid, public readonly int $step = 0)
    {
        $this->onQueue(QueueName::Messaging->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [1, 2, 3, 5, 8];
    }

    public function handle(StatusApplier $statuses): void
    {
        $sequence = ['sent', 'delivered', 'read'];

        // Throws MessageNotYetKnown (→ retried) until the send job has stored the wamid.
        $statuses->apply(['id' => $this->wamid, 'status' => $sequence[$this->step], 'timestamp' => (string) now()->timestamp]);

        if ($this->step < count($sequence) - 1) {
            self::dispatch($this->wamid, $this->step + 1)->delay(now()->addSeconds($this->step === 0 ? 2 : 4));
        }
    }
}
