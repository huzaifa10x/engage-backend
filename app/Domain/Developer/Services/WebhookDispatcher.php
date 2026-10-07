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
     */
    public function emit(string $tenantId, string $event, array $data, ?string $dedupe = null): void
    {
        try {
            $tenant = $this->context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null || ! $this->entitlements->for($tenant)->allows(FeatureKey::Webhooks)) {
                return;
            }

            $this->context->run($tenant, function () use ($tenant, $event, $data, $dedupe): void {
                $endpoints = WebhookEndpoint::query()->where('status', 'active')->get()->filter(fn (WebhookEndpoint $e) => $e->listensTo($event));

                foreach ($endpoints as $endpoint) {
                    if ($dedupe !== null && ! Cache::add("wh-once:{$endpoint->id}:{$event}:{$dedupe}", 1, now()->addDay())) {
                        continue;
                    }
                    $this->queue($endpoint, $event, $this->envelope($tenant->id, $event, $data));
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function envelope(string $tenantId, string $event, array $data): array
    {
        return ['id' => 'evt_'.Str::lower((string) Str::ulid()), 'event' => $event, 'created_at' => now()->toIso8601String(), 'workspace_id' => $tenantId, 'data' => $data];
    }
}
