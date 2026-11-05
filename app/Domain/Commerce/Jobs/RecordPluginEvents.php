<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Services\OrderPayload;
use App\Domain\Commerce\Services\Orders;
use App\Domain\Integrations\Jobs\HandleStoreWebhook;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Order events from the WooCommerce plugin, already verified (signature, timestamp) by the
 * controller, which answers at once. Recording them here keeps the store's request short during a
 * flash sale; repeats are ignored by order + event + time, so a retried job or event is harmless.
 */
final class RecordPluginEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    /** @param list<array<string, mixed>> $events */
    public function __construct(public readonly string $integrationId, public readonly array $events)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(TenantContext $context, Orders $orders): void
    {
        /** @var ?Integration $integration */
        $integration = $context->bypass(fn () => Integration::query()->find($this->integrationId));
        $tenant = $integration === null ? null : $context->bypass(fn () => Tenant::query()->find($integration->tenant_id));
        if ($integration === null || $tenant === null) {
            return; // disconnected since the events arrived
        }

        $context->run($tenant, function () use ($integration, $orders) {
            foreach ($this->events as $e) {
                $order = is_array($e['order'] ?? null) ? OrderPayload::parse($e['order']) : null;
                if ($order === null) {
                    continue;
                }
                $event = (string) ($e['event'] ?? 'updated');
                try {
                    $at = isset($e['occurred_at']) && is_string($e['occurred_at']) ? Carbon::parse($e['occurred_at'])->utc() : null;
                } catch (Throwable) {
                    $at = null;
                }
                $result = $orders->record($integration, $order, $event, $at, ($e['via'] ?? null) === 'catch_up' ? 'catch_up' : 'plugin');
                if (! $result['duplicate']) {
                    // Store messages (order confirmed, shipped, cancelled) use the same events.
                    HandleStoreWebhook::dispatch($integration->id, $event === 'created' ? 'order.created' : 'order.updated', $e['order'], true);
                }
            }
        });
    }
}
