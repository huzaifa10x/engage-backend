<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Jobs;

use App\Application\WhatsApp\ManageChannels;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-reads quality / limit / throughput from Graph. Unique per number = natural debounce. */
final class RefreshPhoneNumber implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $phoneNumberId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->phoneNumberId;
    }

    public function handle(TenantContext $context, ManageChannels $channels): void
    {
        $number = $context->bypass(fn () => PhoneNumber::query()->find($this->phoneNumberId));
        $tenant = $number ? $context->bypass(fn () => Tenant::query()->find($number->tenant_id)) : null;

        if ($number !== null && $tenant !== null) {
            $context->run($tenant, fn () => $channels->refreshNumber($number));
        }
    }
}
