<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Jobs;

use App\Domain\Commerce\Services\OrderPayload;
use App\Domain\Commerce\Services\Orders;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Providers\ShopifyPayload;
use App\Domain\Integrations\Providers\WooCommercePayload;
use App\Domain\Integrations\Services\CommerceEvents;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A store's notification, already verified by the controller, handled off the request so the
 * store gets its answer immediately (Shopify gives up after five seconds).
 */
final class HandleStoreWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $payload
     * @param  bool  $notifyOnly  the order is already in Sales (sent by the plugin): only the store messages remain
     */
    public function __construct(public readonly string $integrationId, public readonly string $topic, public readonly array $payload, public readonly bool $notifyOnly = false)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(TenantContext $context, CommerceEvents $events, Orders $orders): void
    {
        /** @var ?Integration $integration */
        $integration = $context->bypass(fn () => Integration::query()->find($this->integrationId));
        $tenant = $integration === null ? null : $context->bypass(fn () => Tenant::query()->find($integration->tenant_id));
        if ($integration === null || $tenant === null) {
            return; // disconnected since the notification arrived
        }

        $context->run($tenant, function () use ($integration, $events, $orders): void {
            // WooCommerce order webhooks (REST-key connection) also keep Sales up to date.
            if (! $this->notifyOnly && $integration->provider === Integration::WOOCOMMERCE && str_starts_with($this->topic, 'order.')
                && ($order = OrderPayload::parse($this->payload)) !== null) {
                $orders->record($integration, $order, Orders::eventFor($order->status, $this->topic === 'order.created'), null, 'webhook');
            }

            $storeEvents = $integration->provider === Integration::SHOPIFY
                ? array_filter([ShopifyPayload::event($this->topic, $this->payload, $integration->name)])
                : WooCommercePayload::events($this->payload, $integration->name);

            foreach ($storeEvents as $event) {
                $events->ingest($integration, $event);
            }
        });
    }
}
