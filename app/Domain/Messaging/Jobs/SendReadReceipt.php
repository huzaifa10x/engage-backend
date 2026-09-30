<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Blue ticks on the customer's phone when an agent opens the conversation. Best effort. */
final class SendReadReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $phoneNumberId, public readonly string $wamid)
    {
        $this->onQueue(QueueName::Messaging->value);
    }

    public function handle(GraphClient $graph, WhatsappCredentials $credentials): void
    {
        $number = PhoneNumber::query()->with('wabaAccount')->find($this->phoneNumberId);
        if ($number?->wabaAccount === null) {
            return;
        }

        try {
            $graph->markRead($number->phone_number_id, $this->wamid, $credentials->tokenFor($number->wabaAccount));
        } catch (MetaApiException) {
            // Read receipts are cosmetic; never retry-storm on them.
        }
    }
}
