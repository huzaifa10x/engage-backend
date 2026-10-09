<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Models\IntegrationRule;
use App\Domain\Integrations\Providers\ShopifyConnector;
use App\Domain\Integrations\Providers\WooCommerceConnector;
use App\Domain\Integrations\Services\CommerceEvents;
use App\Domain\Integrations\Services\Skip;
use App\Domain\Integrations\Services\StorePhone;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Portal → Integrations: connect stores, choose the message each store event sends, see what happened. */
final class IntegrationController extends Controller
{
    private const MAX_STORES = 10;

    /** What a test message is filled with, so the template can be judged before real orders arrive. */
    private const SAMPLE = [
        'customer_first_name' => 'Sara', 'customer_name' => 'Sara Ahmed', 'order_number' => '1042', 'order_total' => 'AED 249.00', 'items' => '2 x Linen shirt, Canvas tote',
        'first_item' => 'Linen shirt', 'tracking_number' => 'TRK123456789', 'tracking_company' => 'Aramex', 'tracking_url' => 'https://example.com/track/TRK123456789',
        'order_status_url' => 'https://example.com/orders/1042', 'order_status_path' => 'orders/1042', 'checkout_url' => 'https://example.com/checkouts/abc123', 'checkout_path' => 'checkouts/abc123',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function index(ShopifyConnector $shopify): JsonResponse
    {
        $set = $this->entitlements->for($this->context->tenant());

        return response()->json(['data' => [
            'can' => ['stores' => $set->allows(FeatureKey::Integrations), 'api' => $set->allows(FeatureKey::ApiAccess), 'webhooks' => $set->allows(FeatureKey::Webhooks)],
            'providers' => ['shopify' => ['available' => $shopify->configured()], 'woocommerce' => ['available' => true]],
            'api_base_url' => rtrim((string) config('app.url'), '/').'/api/public/v1',
            'events' => collect(Integration::EVENTS)->map(fn (array $e, string $key) => ['key' => $key] + $e)->values(),
            'fields' => collect(Integration::FIELDS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'sample' => self::SAMPLE[$key] ?? ''])->values(),
            'integrations' => Integration::query()->orderBy('created_at')->get()->map(fn (Integration $i) => $this->present($i)),
        ]]);
    }

    public function show(Integration $integration): JsonResponse
    {
        return response()->json(['data' => $this->present($integration, withRules: true)]);
    }

    /** Step one of connecting Shopify: where to send the merchant so they can approve the app. */
    public function shopifyInstall(Request $request, ShopifyConnector $shopify): JsonResponse
    {
        $this->ensureRoom();
        if (! $shopify->configured()) {
            throw ValidationException::withMessages(['shop' => 'Shopify is not available yet. Please contact support.']);
        }
        $data = $request->validate(['shop' => ['required', 'string', 'max:255']]);
        $shop = $shopify->shop($data['shop']) ?? throw ValidationException::withMessages(['shop' => 'Enter your store\'s Shopify address, for example my-store.myshopify.com.']);

        return response()->json(['data' => ['url' => $shopify->installUrl($shop, $this->context->id(), $this->context->membership()?->id)]]);
    }

    public function connectWooCommerce(Request $request, WooCommerceConnector $woo): JsonResponse
    {
        $this->ensureRoom();
        $data = $request->validate([
            'store_url' => ['required', 'string', 'max:255'],
            'consumer_key' => ['required', 'string', 'starts_with:ck_', 'max:100'],
            'consumer_secret' => ['required', 'string', 'starts_with:cs_', 'max:100'],
            'default_country_code' => ['nullable', 'string', 'regex:/^\+?\d{1,4}$/'],
        ], ['consumer_key.starts_with' => 'The consumer key starts with ck_.', 'consumer_secret.starts_with' => 'The consumer secret starts with cs_.']);

        $integration = $woo->connect($data['store_url'], $data['consumer_key'], $data['consumer_secret'], $this->context->membership()?->id);
        if (! empty($data['default_country_code'])) {
            $integration->forceFill(['settings' => ['default_country_code' => ltrim($data['default_country_code'], '+')] + (array) $integration->settings])->save();
        }
        $this->audit->record('integration.connected', null, after: ['provider' => $integration->provider, 'store' => $integration->external_id], meta: ['integration_id' => $integration->id]);

        return response()->json(['data' => $this->present($integration, withRules: true)], 201);
    }

    public function update(Request $request, Integration $integration): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:active,paused'],
            'default_country_code' => ['sometimes', 'nullable', 'string', 'regex:/^\+?\d{1,4}$/'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:40'],
            'trust_store_consent' => ['sometimes', 'boolean'],
        ]);
        $settings = (array) $integration->settings;
        foreach (['default_country_code', 'tag', 'trust_store_consent'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = is_string($data[$key]) ? ltrim(trim($data[$key]), '+') : $data[$key];
            }
        }
        $integration->forceFill(array_filter(['status' => $data['status'] ?? null]) + ['settings' => $settings])->save();
        $this->audit->record('integration.updated', null, after: $data, meta: ['integration_id' => $integration->id]);

        return response()->json(['data' => $this->present($integration, withRules: true)]);
    }

    public function destroy(Integration $integration, WooCommerceConnector $woo): JsonResponse
    {
        if ($integration->provider === Integration::WOOCOMMERCE) {
            $woo->disconnect($integration);
        }
        $this->audit->record('integration.disconnected', null, meta: ['integration_id' => $integration->id, 'provider' => $integration->provider, 'store' => $integration->external_id]);
        $integration->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Choose (or switch off) the template one store event sends. */
    public function saveRule(Request $request, Integration $integration, string $event): JsonResponse
    {
        $data = $this->ruleData($request, $integration, $event);
        $rule = IntegrationRule::query()->updateOrCreate(['integration_id' => $integration->id, 'event' => $event], $data);
        $this->audit->record('integration.rule_saved', null, after: ['event' => $event, 'enabled' => $rule->enabled, 'template' => $rule->template_name], meta: ['integration_id' => $integration->id]);

        return response()->json(['data' => $this->present($integration, withRules: true)]);
    }

    /** Sends the chosen template with sample values to a number of the user's choice. */
    public function testRule(Request $request, Integration $integration, string $event, CommerceEvents $events): JsonResponse
    {
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Integrations);
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $rule = IntegrationRule::query()->where('integration_id', $integration->id)->where('event', $event)->first();
        if ($rule === null || $rule->template_name === null) {
            throw ValidationException::withMessages(['phone' => 'Choose a template and save it first.']);
        }
        $waId = StorePhone::normalize($data['phone'], null, (string) $integration->setting('default_country_code', ''))
            ?? throw ValidationException::withMessages(['phone' => 'Enter the number in international format, for example +971501234567.']);

        try {
            $message = $events->send($integration, $rule, $events->contact($integration, $waId, []), self::SAMPLE + ['store_name' => $integration->name], 'integration-test:'.bin2hex(random_bytes(8)));
        } catch (Skip $skip) {
            throw ValidationException::withMessages(['phone' => $skip->getMessage()]);
        }

        return response()->json(['data' => ['message_id' => $message->id]], 202);
    }

    public function events(Request $request, Integration $integration): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,sent,skipped,failed,cancelled']]);
        $page = IntegrationEvent::query()->where('integration_id', $integration->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (IntegrationEvent $e) => [
                'id' => $e->id, 'event' => $e->event, 'status' => $e->status, 'detail' => $e->detail, 'reference' => $e->data['order_number'] ?? null,
                'customer' => $e->data['customer_name'] ?? null, 'phone' => $e->phone, 'contact_id' => $e->contact_id, 'message_id' => $e->message_id,
                'due_at' => $e->due_at?->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String(),
            ]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** @return array<string, mixed> */
    private function ruleData(Request $request, Integration $integration, string $event): array
    {
        if (! in_array($event, $integration->events(), true)) {
            abort(404);
        }
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'phone_number_id' => ['nullable', 'uuid'],
            'template_name' => ['required_if:enabled,true', 'nullable', 'string', 'max:512'],
            'template_language' => ['required_with:template_name', 'nullable', 'string', 'max:15'],
            'variables' => ['nullable', 'array'],
            'variables.header' => ['nullable', 'array', 'max:1'],
            'variables.header.*' => ['nullable', 'string', 'max:200'],
            'variables.body' => ['nullable', 'array', 'max:50'],
            'variables.body.*' => ['nullable', 'string', 'max:500'],
            'variables.buttons' => ['nullable', 'array', 'max:10'],
            'variables.buttons.*' => ['nullable', 'string', 'max:500'],
            'delay_minutes' => ['nullable', 'integer', 'min:'.CommerceEvents::MIN_ABANDONED_DELAY, 'max:4320'],
        ], ['template_name.required_if' => 'Choose a template before switching this message on.']);

        if (! empty($data['phone_number_id']) && ! PhoneNumber::query()->whereKey($data['phone_number_id'])->exists()) {
            throw ValidationException::withMessages(['phone_number_id' => 'Choose one of your WhatsApp numbers.']);
        }

        // The template must be approved, must not need a file in its header, and every variable must be filled in.
        if (! empty($data['template_name'])) {
            /** @var ?MessageTemplate $template */
            $template = MessageTemplate::query()->where('name', $data['template_name'])->where('language', $data['template_language'])
                ->when(! empty($data['phone_number_id']), fn ($q) => $q->where('waba_account_id', PhoneNumber::query()->whereKey($data['phone_number_id'])->value('waba_account_id')))->first();
            if ($template === null || ! $template->isSendable()) {
                throw ValidationException::withMessages(['template_name' => 'Choose an approved template.']);
            }
            $needs = $template->variables();
            if (in_array($needs['header_format'], ['IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'], true)) {
                throw ValidationException::withMessages(['template_name' => 'Templates with an image, video, document or location header cannot be used here yet. Choose one with a text header or no header.']);
            }
            $given = (array) ($data['variables'] ?? []);
            $filled = fn (array $values) => count(array_filter($values, fn ($v) => trim((string) $v) !== ''));
            if ($filled((array) ($given['body'] ?? [])) < count($needs['body']) || $filled((array) ($given['header'] ?? [])) < count($needs['header'])) {
                throw ValidationException::withMessages(['variables' => 'Choose a value for every variable in the template.']);
            }
            foreach ($needs['buttons'] as $button) {
                if ($button['variable'] && $button['type'] === 'URL' && trim((string) ($given['buttons'][$button['index']] ?? '')) === '') {
                    throw ValidationException::withMessages(['variables' => "Choose a value for the \"{$button['text']}\" button."]);
                }
            }
        }

        return [
            'enabled' => (bool) $data['enabled'], 'phone_number_id' => $data['phone_number_id'] ?? null,
            'template_name' => $data['template_name'] ?? null, 'template_language' => $data['template_language'] ?? null,
            'variables' => $data['variables'] ?? null,
            'delay_minutes' => $event === 'checkout_abandoned' ? (int) ($data['delay_minutes'] ?? CommerceEvents::DEFAULT_ABANDONED_DELAY) : 0,
        ];
    }

    private function ensureRoom(): void
    {
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Integrations);
        if (Integration::query()->count() >= self::MAX_STORES) {
            throw ValidationException::withMessages(['store_url' => 'You have reached the limit of '.self::MAX_STORES.' connected stores.']);
        }
    }

    /** @return array<string, mixed> */
    private function present(Integration $i, bool $withRules = false): array
    {
        $out = [
            'id' => $i->id, 'provider' => $i->provider, 'name' => $i->name, 'store' => $i->external_id, 'status' => $i->status,
            'default_country_code' => $i->setting('default_country_code'), 'tag' => $i->setting('tag'), 'trust_store_consent' => (bool) $i->setting('trust_store_consent', false),
            'events' => $i->events(), 'last_event_at' => $i->last_event_at?->toIso8601String(), 'created_at' => $i->created_at?->toIso8601String(),
            'active_rules' => IntegrationRule::query()->where('integration_id', $i->id)->where('enabled', true)->count(),
        ];
        if ($withRules) {
            $rules = IntegrationRule::query()->where('integration_id', $i->id)->get()->keyBy('event');
            $out['rules'] = collect($i->events())->map(fn (string $event) => [
                'event' => $event, 'enabled' => (bool) ($rules[$event]->enabled ?? false), 'phone_number_id' => $rules[$event]->phone_number_id ?? null,
                'template_name' => $rules[$event]->template_name ?? null, 'template_language' => $rules[$event]->template_language ?? null,
                'variables' => $rules[$event]->variables ?? null,
                'delay_minutes' => $event === 'checkout_abandoned' ? (int) ($rules[$event]->delay_minutes ?? CommerceEvents::DEFAULT_ABANDONED_DELAY) : 0,
            ])->values();
        }

        return $out;
    }
}
