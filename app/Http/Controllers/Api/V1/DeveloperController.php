<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Developer\Models\ApiKey;
use App\Domain\Developer\Models\WebhookDelivery;
use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Developer\Services\WebhookDispatcher;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Portal → Developer: API keys, webhook endpoints and the delivery log. */
final class DeveloperController extends Controller
{
    private const MAX_KEYS = 20;

    private const MAX_ENDPOINTS = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function overview(): JsonResponse
    {
        $set = $this->entitlements->for($this->context->tenant());

        return response()->json(['data' => [
            'base_url' => rtrim((string) config('app.url'), '/').'/api/public/v1',
            'can' => ['api' => $set->allows(FeatureKey::ApiAccess), 'webhooks' => $set->allows(FeatureKey::Webhooks)],
            'rate_limit_per_minute' => $set->get(FeatureKey::ApiRateLimitPerMinute)->limit,
            'scopes' => collect(ApiKey::SCOPES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'events' => collect(WebhookEndpoint::EVENTS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
        ]]);
    }

    // ── API keys ───────────────────────────────────────────────────────────────────────────

    public function keys(): JsonResponse
    {
        return response()->json(['data' => ApiKey::query()->orderByDesc('created_at')->get()->map(fn (ApiKey $k) => $this->key($k))]);
    }

    /** The full key is returned here once and never again: only its hash is kept. */
    public function createKey(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();
        $this->entitlements->ensureEnabled($tenant, FeatureKey::ApiAccess);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(ApiKey::SCOPES))],
            'expires_in_days' => ['nullable', 'integer', 'in:30,90,365'],
        ]);
        if (ApiKey::query()->whereNull('revoked_at')->count() >= self::MAX_KEYS) {
            throw ValidationException::withMessages(['name' => 'You have reached the limit of '.self::MAX_KEYS.' active keys. Revoke one you no longer use.']);
        }

        $plain = 'eng_live_'.Str::random(40);
        $key = ApiKey::query()->create([
            'name' => $data['name'],
            'prefix' => substr($plain, 0, 13),
            'key_hash' => ApiKey::hash($plain),
            'scopes' => array_values(array_unique($data['scopes'])),
            'expires_at' => isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            'created_by_membership_id' => $this->context->membership()?->id,
        ]);
        $this->audit->record('api_key.created', null, after: ['name' => $key->name, 'scopes' => $key->scopes], meta: ['api_key_id' => $key->id]);

        return response()->json(['data' => $this->key($key) + ['key' => $plain]], 201);
    }

    public function revokeKey(ApiKey $apiKey): JsonResponse
    {
        if ($apiKey->revoked_at === null) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
            $this->audit->record('api_key.revoked', null, meta: ['api_key_id' => $apiKey->id, 'name' => $apiKey->name]);
        }

        return response()->json(['data' => $this->key($apiKey)]);
    }

    // ── Webhook endpoints ──────────────────────────────────────────────────────────────────

    public function endpoints(): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::query()->orderBy('created_at')->get()->map(fn (WebhookEndpoint $e) => $this->endpoint($e))]);
    }

    /** The signing secret is returned on creation (and on rotation) only. */
    public function createEndpoint(Request $request, UrlGuard $guard): JsonResponse
    {
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Webhooks);
        $data = $this->endpointData($request, $guard);
        if (WebhookEndpoint::query()->count() >= self::MAX_ENDPOINTS) {
            throw ValidationException::withMessages(['url' => 'You have reached the limit of '.self::MAX_ENDPOINTS.' endpoints.']);
        }

        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::query()->create($data + ['secret' => $secret, 'status' => 'active']);
        $this->audit->record('webhook_endpoint.created', null, after: ['url' => $endpoint->url, 'events' => $endpoint->events], meta: ['endpoint_id' => $endpoint->id]);

        return response()->json(['data' => $this->endpoint($endpoint) + ['secret' => $secret]], 201);
    }

    public function updateEndpoint(Request $request, WebhookEndpoint $endpoint, UrlGuard $guard): JsonResponse
    {
        $data = $this->endpointData($request, $guard, partial: true) + $request->validate(['status' => ['sometimes', 'in:active,paused']]);
        // Switching an endpoint back on clears the failure count that switched it off.
        if (($data['status'] ?? null) === 'active' && $endpoint->status !== 'active') {
            $data += ['consecutive_failures' => 0, 'disabled_at' => null];
        }
        $endpoint->forceFill($data)->save();
        $this->audit->record('webhook_endpoint.updated', null, after: array_diff_key($data, ['consecutive_failures' => 1, 'disabled_at' => 1]), meta: ['endpoint_id' => $endpoint->id]);

        return response()->json(['data' => $this->endpoint($endpoint)]);
    }

    public function deleteEndpoint(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->audit->record('webhook_endpoint.deleted', null, meta: ['endpoint_id' => $endpoint->id, 'url' => $endpoint->url]);
        $endpoint->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function rotateSecret(WebhookEndpoint $endpoint): JsonResponse
    {
        $secret = 'whsec_'.Str::random(40);
        $endpoint->forceFill(['secret' => $secret])->save();
        $this->audit->record('webhook_endpoint.secret_rotated', null, meta: ['endpoint_id' => $endpoint->id]);

        return response()->json(['data' => $this->endpoint($endpoint) + ['secret' => $secret]]);
    }

    /** Sends a clearly marked sample event, so the receiving side can be checked without waiting for a real one. */
    public function testEndpoint(WebhookEndpoint $endpoint, WebhookDispatcher $webhooks): JsonResponse
    {
        $payload = $webhooks->envelope($endpoint->tenant_id, 'message.received', [
            'id' => (string) Str::uuid(), 'direction' => 'inbound', 'type' => 'text', 'status' => 'received', 'text' => 'This is a test event from 10X Engage.',
            'contact' => ['id' => (string) Str::uuid(), 'phone' => '+971500000000', 'name' => 'Test Contact'],
        ]) + ['test' => true];

        return response()->json(['data' => $this->delivery($webhooks->queue($endpoint, 'message.received', $payload)->refresh())], 202);
    }

    // ── Delivery log ───────────────────────────────────────────────────────────────────────

    public function deliveries(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint_id' => ['nullable', 'uuid'], 'status' => ['nullable', 'in:pending,delivered,failed']]);
        $page = WebhookDelivery::query()
            ->when($data['endpoint_id'] ?? null, fn ($q, $id) => $q->where('webhook_endpoint_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(30);

        return response()->json(['data' => collect($page->items())->map(fn (WebhookDelivery $d) => $this->delivery($d)), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** Sends the same event again as a new delivery (the original stays in the log as it was). */
    public function resend(WebhookDelivery $delivery, WebhookDispatcher $webhooks): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($delivery->webhook_endpoint_id);
        if ($endpoint->status !== 'active') {
            throw ValidationException::withMessages(['endpoint' => 'Switch the endpoint on before resending.']);
        }

        return response()->json(['data' => $this->delivery($webhooks->queue($endpoint, $delivery->event, $delivery->payload)->refresh())], 202);
    }

    /** @return array<string, mixed> */
    private function endpointData(Request $request, UrlGuard $guard, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'url' => [$rule, 'string', 'max:2000', 'url'],
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'events' => [$rule, 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(WebhookEndpoint::EVENTS))],
        ]);
        if (isset($data['url'])) {
            $guard->assertPublic($data['url']);
        }
        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function key(ApiKey $k): array
    {
        return [
            'id' => $k->id, 'name' => $k->name, 'prefix' => $k->prefix, 'scopes' => $k->scopes,
            'status' => $k->revoked_at !== null ? 'revoked' : ($k->expires_at?->isPast() ? 'expired' : 'active'),
            'expires_at' => $k->expires_at?->toIso8601String(), 'last_used_at' => $k->last_used_at?->toIso8601String(), 'last_used_ip' => $k->last_used_ip,
            'created_at' => $k->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function endpoint(WebhookEndpoint $e): array
    {
        return [
            'id' => $e->id, 'url' => $e->url, 'description' => $e->description, 'events' => $e->events, 'status' => $e->status,
            'consecutive_failures' => $e->consecutive_failures, 'last_success_at' => $e->last_success_at?->toIso8601String(),
            'last_failure_at' => $e->last_failure_at?->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function delivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id, 'endpoint_id' => $d->webhook_endpoint_id, 'event' => $d->event, 'status' => $d->status, 'attempts' => $d->attempts,
            'response_status' => $d->response_status, 'response_excerpt' => $d->response_excerpt, 'duration_ms' => $d->duration_ms,
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(), 'delivered_at' => $d->delivered_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(), 'payload' => $d->payload,
        ];
    }
}
