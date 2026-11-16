<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Jobs;

use App\Domain\Conversions\Services\ConversionSender;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one WhatsApp account's queued conversion events. Maintenance queue (its own small pool of
 * workers): it can never hold up messaging, webhooks or flows. One at a time per account.
 */
final class SendConversionEvents implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;          // the sender schedules its own retries per event

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $tenantId, public readonly string $wabaId)
    {
        $this->onQueue(QueueName::Maintenance->value);
    }

    public function uniqueId(): string
    {
        return $this->wabaId;
    }

    public function handle(TenantContext $context, ConversionSender $sender): void
    {
        $tenant = $context->bypass(fn () => Tenant::query()->find($this->tenantId));
        if ($tenant !== null) {
            $context->run($tenant, fn () => $sender->send($this->wabaId));
        }
    }
}
