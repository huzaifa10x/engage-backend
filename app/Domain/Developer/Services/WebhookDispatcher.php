<?php

declare(strict_types=1);

namespace App\Domain\Developer\Services;

use App\Domain\Developer\Jobs\DeliverWebhook;
use App\Domain\Developer\Models\WebhookDelivery;
use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns something that happened in a workspace into deliveries to that workspace's webhook
 * endpoints. Best effort by design: a problem here must never break the action that caused the
 * event (receiving a message, saving a contact), so everything is caught and logged.
 */
final class WebhookDispatcher
{
    public function __construct(private readonly TenantContext $context, private readonly EntitlementService $entitlements) {}

    /**
     * @param  array<string, mixed>  $data  the event's subject, as documented in the API reference
     * @param  ?string  $dedupe  when set, the same event is sent at most once per endpoint for this key
     * @param  array<string, mixed>  $meta  when it happened, its enquiry and who caused it (timeline events)
     */
    public function emit(string $tenantId, string $event, array $data, ?string $dedupe = null, array $meta = []): void
    {
        try {
            $tenant = $this->context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null || ! $this->entitlements->for($tenant)->allows(FeatureKey::Webhooks)) {
                return;
            }

            $this->context->run($tenant, function () use ($tenant, $event, $data, $dedupe, $meta): void {
                $endpoints = WebhookEndpoint::query()->where('status', 'active')->get()->filter(fn (WebhookEndpoint $e) => $e->listensTo($event));

                foreach ($endpoints as $endpoint) {
                    if ($dedupe !== null && ! Cache::add("wh-once:{$endpoint->id}:{$event}:{$dedupe}", 1, now()->addDay())) {
                        continue;
                    }
                    $this->queue($endpoint, $event, $this->envelope($tenant->id, $event, $data, $meta + ['timezone' => $tenant->timezone]));
                }
            });
        } catch (Throwable $e) {
            Log::warning('Webhook event could not be queued', ['tenant' => $tenantId, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $payload */
    public function queue(WebhookEndpoint $endpoint, string $event, array $payload): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create(['webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'payload' => $payload, 'status' => 'pending']);
        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    /**
     * Times are UTC; "timezone" is the workspace's, for showing them as the team sees them.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function envelope(string $tenantId, string $event, array $data, array $meta = []): array
    {
        return ['id' => 'evt_'.Str::lower((string) Str::ulid()), 'event' => $event, 'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'workspace_id' => $tenantId]
            + $meta + ['data' => $data];
    }
}
