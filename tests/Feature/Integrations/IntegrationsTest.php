<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domain\Access\SystemRole;
use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Services\StorePhone;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class IntegrationsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const SHOPIFY_SECRET = 'shpss_test_secret';

    private Tenant $tenant;

    private TenantMembership $owner;

    /** @var list<array<string, mixed>> webhooks the fake WooCommerce store was asked to create */
    private array $wooWebhooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        config(['engage.shopify.client_id' => 'shopify-client-id', 'engage.shopify.client_secret' => self::SHOPIFY_SECRET, 'app.url' => 'https://app.test']);
        $this->tenant = $this->createTenant(['name' => 'Palm Store']);
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        Http::fake([
            'graph.facebook.com/v25.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'order_confirmed', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, we received order {{2}} for {{3}}.']]],
                ['id' => '900002', 'name' => 'come_back', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, you left something in your cart.']]],
                ['id' => '900003', 'name' => 'cart_reminder', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, your cart is waiting.'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Finish order', 'url' => 'https://palm.example/{{1}}']]]]],
                ['id' => '900004', 'name' => 'with_picture', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Thanks!']]],
            ]]),
            'graph.facebook.com/*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.I'.bin2hex(random_bytes(6))]]]),
            'palm.example/wp-json/wc/v3/webhooks*' => function (HttpRequest $request) {
                if ($request->method() === 'DELETE') {
                    return Http::response(['id' => 1]);
                }
                if ($request->header('Authorization')[0] !== 'Basic '.base64_encode('ck_good:cs_good')) {
                    return Http::response(['code' => 'woocommerce_rest_cannot_create'], 401);
                }
                $this->wooWebhooks[] = $request->data();

                return Http::response(['id' => count($this->wooWebhooks) + 10], 201);
            },
            'palm.example/wp-json/' => Http::response(['name' => 'Palm &amp; Co']),
            'palm-store.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_x', 'scope' => 'read_orders', 'expires_in' => 3600, 'refresh_token' => 'shprt_x']),
            'palm-store.myshopify.com/admin/api/*' => fn (HttpRequest $request) => Http::response(str_contains((string) $request['query'], 'webhookSubscriptionCreate')
                ? ['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]]]
                : ['data' => ['shop' => ['name' => 'Palm Store']]]),
        ]);

        $this->actingAsMember($this->owner);
        $this->postJson('/api/v1/templates/sync')->assertOk();
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    private function connectWoo(): string
    {
        return (string) $this->postJson('/api/v1/integrations/woocommerce', ['store_url' => 'palm.example', 'consumer_key' => 'ck_good', 'consumer_secret' => 'cs_good'])->assertCreated()->json('data.id');
    }

    private function connectShopify(): string
    {
        $url = (string) $this->postJson('/api/v1/integrations/shopify/install', ['shop' => 'Palm-Store'])->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $sent);
        $location = (string) $this->get('/api/integrations/shopify/callback?'.http_build_query($this->shopifySigned(['code' => 'abc', 'shop' => 'palm-store.myshopify.com', 'state' => $sent['state'], 'timestamp' => (string) time()])))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/integrations?connected=', $location);

        return substr($location, strpos($location, 'connected=') + 10);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function shopifySigned(array $params): array
    {
        ksort($params);

        return $params + ['hmac' => hash_hmac('sha256', urldecode(http_build_query($params)), self::SHOPIFY_SECRET)];
    }

    /** @param array<string, mixed> $payload */
    private function shopifyWebhook(string $topic, array $payload, ?string $secret = self::SHOPIFY_SECRET): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/integrations/shopify/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_TOPIC' => $topic, 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'palm-store.myshopify.com',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, (string) $secret, true)),
        ], $body);
    }

    /** @param array<string, mixed> $payload */
    private function wooWebhook(string $integrationId, array $payload, string $topic = 'order.created', ?string $secret = null): TestResponse
    {
        /** @var Integration $integration */
        $integration = $this->tenantContext()->bypass(fn () => Integration::query()->findOrFail($integrationId));
        $body = (string) json_encode($payload);

        return $this->call('POST', "/api/integrations/woocommerce/{$integration->public_id}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, $secret ?? (string) $integration->webhook_secret, true)),
        ], $body);
    }

    /** @param array<string, mixed> $rule */
    private function saveRule(string $id, string $event, array $rule): TestResponse
    {
        $this->actingAsMember($this->owner);

        return $this->putJson("/api/v1/integrations/{$id}/rules/{$event}", $rule + ['enabled' => true, 'template_language' => 'en']);
    }

    /** @return array<string, mixed> */
    private function wooOrder(array $overrides = []): array
    {
        return $overrides + [
            'id' => 5001, 'number' => '5001', 'status' => 'processing', 'total' => '249.00', 'currency' => 'AED',
            'billing' => ['first_name' => 'Sara', 'last_name' => 'Ahmed', 'phone' => '050 123 4567', 'country' => 'AE', 'email' => 'sara@example.com'],
            'line_items' => [['name' => 'Linen shirt', 'quantity' => 2], ['name' => 'Canvas tote', 'quantity' => 1]],
        ];
    }

    /** @return Collection<int, IntegrationEvent> */
    private function events()
    {
        return $this->tenantContext()->run($this->tenant, fn () => IntegrationEvent::query()->orderBy('created_at')->orderBy('id')->get());
    }

    /** @return Collection<int, Message> */
    private function sent()
    {
        return $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('direction', Message::OUTBOUND)->orderBy('created_at')->get());
    }

    // ── WooCommerce ────────────────────────────────────────────────────────────────────────

    public function test_a_woocommerce_store_is_connected_and_an_order_sends_the_chosen_template_once(): void
    {
        $id = $this->connectWoo();

        // Two signed webhooks were created in the store, pointing at this integration's own address.
        $this->assertSame(['order.created', 'order.updated'], array_column($this->wooWebhooks, 'topic'));
        $integration = $this->tenantContext()->bypass(fn () => Integration::query()->findOrFail($id));
        $this->assertSame("https://app.test/api/integrations/woocommerce/{$integration->public_id}/webhook", $this->wooWebhooks[0]['delivery_url']);
        $this->assertSame($integration->webhook_secret, $this->wooWebhooks[0]['secret']);
        $this->assertSame('Palm & Co', $integration->name);
        $this->assertStringNotContainsString('cs_good', (string) json_encode($integration->getAttributes()), 'The store keys live in the secret store, not on the row.');

        $this->saveRule($id, 'order_created', ['template_name' => 'order_confirmed', 'variables' => ['body' => ['{{customer_first_name}}', '#{{order_number}}', '{{order_total}}']]])->assertOk()->assertJsonPath('data.rules.0.enabled', true);
        $this->app['auth']->forgetGuards();

        $this->wooWebhook($id, $this->wooOrder())->assertOk();
        $this->wooWebhook($id, $this->wooOrder())->assertOk();                          // the store repeats itself
        $this->wooWebhook($id, $this->wooOrder(), 'order.updated')->assertOk();         // and reports an unrelated change

        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertSame('Hi Sara, we received order #5001 for AED 249.00.', $messages[0]->body);
        $this->assertSame('automation', $messages[0]->origin->value);

        // The customer is now a contact, with the local number made international from the order's country.
        $contact = $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', '971501234567')->firstOrFail());
        $this->assertSame(['Sara Ahmed', 'sara@example.com', 'woocommerce', ['woocommerce']], [$contact->name, $contact->email, $contact->source, $contact->tags]);
        $this->assertSame(['sent'], $this->events()->pluck('status')->all());

        // Completed later: "shipped" has no message switched on, so it is recorded as skipped and nothing is sent.
        $this->wooWebhook($id, $this->wooOrder(['status' => 'completed']), 'order.updated')->assertOk();
        $this->assertSame(['order_created' => 'sent', 'order_fulfilled' => 'skipped'], $this->events()->pluck('status', 'event')->all());
        $this->assertCount(1, $this->sent());

        $this->actingAsMember($this->owner);
        $this->getJson("/api/v1/integrations/{$id}/events")->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.reference', '5001');
    }

    public function test_woocommerce_requests_must_be_signed_and_wrong_keys_are_explained(): void
    {
        $this->postJson('/api/v1/integrations/woocommerce', ['store_url' => 'https://palm.example', 'consumer_key' => 'ck_bad', 'consumer_secret' => 'cs_bad'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['consumer_key']]]]);
        $this->postJson('/api/v1/integrations/woocommerce', ['store_url' => 'https://palm.example', 'consumer_key' => 'nope', 'consumer_secret' => 'cs_x'])->assertStatus(422);

        $id = $this->connectWoo();
        $this->postJson('/api/v1/integrations/woocommerce', ['store_url' => 'https://PALM.example/', 'consumer_key' => 'ck_good', 'consumer_secret' => 'cs_good'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['store_url']]]]);
        $this->app['auth']->forgetGuards();

        $this->wooWebhook($id, $this->wooOrder(), secret: 'someone-else')->assertStatus(401);
        $this->assertCount(0, $this->events());

        // WooCommerce's own "is this address alive" check when the webhook is created carries no signature.
        $integration = $this->tenantContext()->bypass(fn () => Integration::query()->findOrFail($id));
        $this->call('POST', "/api/integrations/woocommerce/{$integration->public_id}/webhook", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'webhook_id=11')->assertOk();
        $this->call('POST', '/api/integrations/woocommerce/'.str_repeat('a', 32).'/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'x'], '{}')->assertStatus(410);

        // Disconnecting removes our webhooks from the store and forgets the store.
        $this->actingAsMember($this->owner);
        $this->deleteJson("/api/v1/integrations/{$id}")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/wp-json/wc/v3/webhooks/11'));
        $this->getJson('/api/v1/integrations')->assertOk()->assertJsonCount(0, 'data.integrations');
    }

    public function test_messages_respect_consent_and_templates_are_checked_when_saved(): void
    {
        $id = $this->connectWoo();
        $this->saveRule($id, 'order_created', ['template_name' => 'with_picture'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['template_name']]]]);
        $this->saveRule($id, 'order_created', ['template_name' => 'order_confirmed', 'variables' => ['body' => ['{{customer_first_name}}']]])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['variables']]]]);
        $this->saveRule($id, 'checkout_abandoned', ['template_name' => 'come_back'])->assertNotFound(); // WooCommerce does not report abandoned checkouts

        // A marketing template is only sent to customers who opted in.
        $this->saveRule($id, 'order_created', ['template_name' => 'come_back', 'variables' => ['body' => ['{{customer_first_name}}']]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->wooWebhook($id, $this->wooOrder())->assertOk();
        $event = $this->events()->first();
        $this->assertSame('skipped', $event->status);
        $this->assertStringContainsString('has not opted in', (string) $event->detail);
        $this->assertCount(0, $this->sent());

        // An order without a phone number, and one whose number cannot be completed, explain themselves too.
        $this->wooWebhook($id, $this->wooOrder(['id' => 5002, 'billing' => ['first_name' => 'No', 'phone' => '']]))->assertOk();
        $this->wooWebhook($id, $this->wooOrder(['id' => 5003, 'billing' => ['first_name' => 'Local', 'phone' => '501234567']]))->assertOk();
        $details = $this->events()->pluck('detail', 'external_id');
        $this->assertStringContainsString('did not give a phone number', (string) $details['5002']);
        $this->assertStringContainsString('no country code', (string) $details['5003']);

        // With a default country code for the store, the same local number works.
        $this->actingAsMember($this->owner);
        $this->patchJson("/api/v1/integrations/{$id}", ['default_country_code' => '+971'])->assertOk()->assertJsonPath('data.default_country_code', '971');
        $this->saveRule($id, 'order_created', ['template_name' => 'order_confirmed', 'variables' => ['body' => ['{{customer_first_name}}', '{{order_number}}', '{{tracking_number}}']]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->wooWebhook($id, $this->wooOrder(['id' => 5004, 'number' => '5004', 'billing' => ['first_name' => '', 'phone' => '501234567']]))->assertOk();
        // Values the store did not send never break the message: the name becomes "there", anything else a dash.
        $this->assertSame('Hi there, we received order 5004 for -.', $this->sent()->last()->body);

        // A paused integration sends nothing.
        $this->actingAsMember($this->owner);
        $this->patchJson("/api/v1/integrations/{$id}", ['status' => 'paused'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->wooWebhook($id, $this->wooOrder(['id' => 5005]))->assertOk();
        $this->assertSame('The integration was paused.', $this->events()->firstWhere('external_id', '5005')->detail);
    }

    // ── Shopify ────────────────────────────────────────────────────────────────────────────

    public function test_a_shopify_store_is_connected_through_shopify_and_orders_send_messages(): void
    {
        $id = $this->connectShopify();
        $integration = $this->tenantContext()->bypass(fn () => Integration::query()->findOrFail($id));
        $this->assertSame(['shopify', 'palm-store.myshopify.com', 'Palm Store', $this->tenant->id], [$integration->provider, $integration->external_id, $integration->name, $integration->tenant_id]);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/admin/oauth/access_token') && $r['code'] === 'abc' && $r['client_secret'] === self::SHOPIFY_SECRET);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/graphql.json') && ($r['variables']['topic'] ?? '') === 'ORDERS_CREATE' && $r['variables']['sub']['uri'] === 'https://app.test/api/integrations/shopify/webhook');

        $this->saveRule($id, 'order_fulfilled', ['template_name' => 'order_confirmed', 'variables' => ['body' => ['{{customer_first_name}}', '{{order_number}}', '{{tracking_company}} {{tracking_number}}']]])->assertOk();
        $this->app['auth']->forgetGuards();

        $order = ['id' => 820982911946154508, 'name' => '#1042', 'phone' => '+971 50 123 4567', 'total_price' => '249.00', 'currency' => 'AED',
            'customer' => ['first_name' => 'Sara', 'last_name' => 'Ahmed'], 'order_status_url' => 'https://palm-store.myshopify.com/orders/abc/authenticate?key=1',
            'fulfillments' => [['tracking_number' => 'TRK1', 'tracking_company' => 'Aramex', 'tracking_url' => 'https://aramex.example/TRK1']]];
        $this->shopifyWebhook('orders/fulfilled', $order, 'wrong-secret')->assertStatus(401);
        $this->shopifyWebhook('orders/fulfilled', $order)->assertOk();
        $this->shopifyWebhook('orders/fulfilled', $order)->assertOk();

        $this->assertCount(1, $this->sent());
        $this->assertSame('Hi Sara, we received order 1042 for Aramex TRK1.', $this->sent()[0]->body);
        $this->assertSame('orders/abc/authenticate?key=1', $this->events()[0]->data['order_status_path']);

        // Uninstalling the app in Shopify disconnects the store here.
        $this->shopifyWebhook('app/uninstalled', ['id' => 1])->assertOk();
        $this->assertNull($this->tenantContext()->bypass(fn () => Integration::query()->find($id)));
        $this->shopifyWebhook('orders/fulfilled', ['id' => 2] + $order)->assertOk(); // unknown store: accepted and ignored
    }

    public function test_the_shopify_return_trip_cannot_be_forged_or_replayed(): void
    {
        $url = (string) $this->postJson('/api/v1/integrations/shopify/install', ['shop' => 'palm-store.myshopify.com'])->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://palm-store.myshopify.com/admin/oauth/authorize?client_id=shopify-client-id&scope=read_orders&redirect_uri='.urlencode('https://app.test/api/integrations/shopify/callback'), $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $sent);
        $this->postJson('/api/v1/integrations/shopify/install', ['shop' => 'evil.example.com/x'])->assertStatus(422);
        $this->app['auth']->forgetGuards();

        $params = ['code' => 'abc', 'shop' => 'palm-store.myshopify.com', 'state' => $sent['state'], 'timestamp' => (string) time()];
        $failed = fn (array $query) => $this->assertStringContainsString('/integrations?error=', (string) $this->get('/api/integrations/shopify/callback?'.http_build_query($query))->assertRedirect()->headers->get('Location'));

        $failed($params + ['hmac' => str_repeat('0', 64)]);                                                // not signed by Shopify
        $failed($this->shopifySigned(['state' => 'made-up'] + $params));                                    // nobody started this
        $this->actingAsMember($this->owner);
        parse_str((string) parse_url((string) $this->postJson('/api/v1/integrations/shopify/install', ['shop' => 'other-store'])->json('data.url'), PHP_URL_QUERY), $otherStore);
        $this->app['auth']->forgetGuards();
        $failed($this->shopifySigned(['state' => $otherStore['state']] + $params));                         // started for a different store
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => Integration::query()->count()));

        // The real one works without any login session (Shopify, not our portal, sends the browser back) …
        $this->get('/api/integrations/shopify/callback?'.http_build_query($this->shopifySigned($params)))->assertRedirectContains('/integrations?connected=');
        // … and only once.
        $failed($this->shopifySigned($params));
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => Integration::query()->count()));

        // The same store cannot be attached to a second workspace.
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $again = (string) $this->postJson('/api/v1/integrations/shopify/install', ['shop' => 'palm-store'])->assertOk()->json('data.url');
        parse_str((string) parse_url($again, PHP_URL_QUERY), $second);
        $failed($this->shopifySigned(['state' => $second['state']] + $params));
        $this->getJson('/api/v1/integrations')->assertOk()->assertJsonCount(0, 'data.integrations');
    }

    public function test_an_abandoned_checkout_is_reminded_after_the_wait_unless_the_order_is_completed(): void
    {
        $id = $this->connectShopify();
        $this->saveRule($id, 'checkout_abandoned', ['template_name' => 'cart_reminder', 'delay_minutes' => 30, 'variables' => ['body' => ['{{customer_first_name}}'], 'buttons' => ['0' => '{{checkout_path}}']]])->assertOk();
        $this->saveRule($id, 'checkout_abandoned', ['template_name' => 'cart_reminder', 'delay_minutes' => 5, 'variables' => ['body' => ['x'], 'buttons' => ['0' => 'y']]])->assertStatus(422); // too soon
        $this->app['auth']->forgetGuards();

        $checkout = fn (string $token, array $more = []) => $more + ['token' => $token, 'phone' => '+971501234567', 'customer' => ['first_name' => 'Sara'],
            'abandoned_checkout_url' => "https://palm.example/checkouts/{$token}/recover?key=9", 'total_price' => '99.00', 'currency' => 'AED'];

        $this->shopifyWebhook('checkouts/create', $checkout('tok-a'))->assertOk();
        $this->shopifyWebhook('checkouts/update', $checkout('tok-a'))->assertOk();   // still typing: one event, the wait restarts
        $this->shopifyWebhook('checkouts/create', $checkout('tok-b'))->assertOk();
        $this->assertSame(['pending', 'pending'], $this->events()->pluck('status')->all());

        // Nothing goes out before the wait is over.
        $this->artisan('engage:integrations:run')->assertSuccessful();
        $this->assertCount(0, $this->sent());

        // Customer B completes the order: their reminder is cancelled when the order arrives.
        $this->shopifyWebhook('orders/create', ['id' => 77, 'name' => '#77', 'checkout_token' => 'tok-b', 'phone' => '+971501234567'])->assertOk();

        $this->travel(31)->minutes();
        $this->artisan('engage:integrations:run')->assertSuccessful();
        $this->artisan('engage:integrations:run')->assertSuccessful(); // running again sends nothing twice

        $statuses = $this->events()->where('event', 'checkout_abandoned')->pluck('status', 'external_id')->all();
        $this->assertSame(['tok-a' => 'sent', 'tok-b' => 'cancelled'], $statuses);
        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertSame('Hi Sara, your cart is waiting.', $messages[0]->body);
        $button = collect($messages[0]->template['components'])->firstWhere('type', 'button');
        $this->assertSame('checkouts/tok-a/recover?key=9', $button['parameters'][0]['text']);
    }

    // ── access ─────────────────────────────────────────────────────────────────────────────

    public function test_integrations_need_the_plan_and_the_permission_and_stay_inside_the_workspace(): void
    {
        $id = $this->connectWoo();
        $this->getJson('/api/v1/integrations')->assertOk()->assertJsonPath('data.can.stores', true)->assertJsonPath('data.providers.shopify.available', true)->assertJsonCount(1, 'data.integrations');

        // An agent cannot see or change integrations.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/integrations')->assertForbidden();
        $this->deleteJson("/api/v1/integrations/{$id}")->assertForbidden();

        // Another workspace cannot reach this store, and a plan without integrations cannot connect one.
        $other = $this->createTenant();
        $this->subscribe($other, 'free');
        $this->actingAsMember($this->addMember($other));
        $this->getJson("/api/v1/integrations/{$id}")->assertNotFound();
        $this->getJson('/api/v1/integrations')->assertOk()->assertJsonPath('data.can.stores', false)->assertJsonCount(0, 'data.integrations');
        $this->postJson('/api/v1/integrations/woocommerce', ['store_url' => 'https://second.example', 'consumer_key' => 'ck_good', 'consumer_secret' => 'cs_good'])->assertStatus(403);
        $this->postJson('/api/v1/integrations/shopify/install', ['shop' => 'palm-store'])->assertStatus(403);
    }

    public function test_a_test_message_uses_sample_values(): void
    {
        $id = $this->connectWoo();
        $this->postJson("/api/v1/integrations/{$id}/rules/order_created/test", ['phone' => '+971509998877'])->assertStatus(422); // nothing chosen yet
        $this->saveRule($id, 'order_created', ['enabled' => false, 'template_name' => 'order_confirmed', 'variables' => ['body' => ['{{customer_first_name}}', '{{order_number}}', '{{store_name}}']]])->assertOk();
        $this->postJson("/api/v1/integrations/{$id}/rules/order_created/test", ['phone' => '+971509998877'])->assertStatus(202);
        $this->assertSame('Hi Sara, we received order 1042 for Palm & Co.', $this->sent()->last()->body);
    }

    public function test_phone_numbers_from_stores_are_made_international(): void
    {
        $this->assertSame('971501234567', StorePhone::normalize('+971 50 123 4567'));
        $this->assertSame('971501234567', StorePhone::normalize('00971501234567'));
        $this->assertSame('971501234567', StorePhone::normalize('050 123 4567', 'AE'));
        $this->assertSame('971501234567', StorePhone::normalize('501234567', null, '971'));
        $this->assertSame('971501234567', StorePhone::normalize('971501234567', 'AE'));
        $this->assertSame('923001234567', StorePhone::normalize('0300-1234567', 'pk', '971'), 'The order\'s country wins over the store default.');
        $this->assertSame('14155550123', StorePhone::normalize('(415) 555-0123', 'US'));
        $this->assertNull(StorePhone::normalize('050 123 4567'));
        $this->assertNull(StorePhone::normalize(''));
    }

    // ── Zapier / Make ──────────────────────────────────────────────────────────────────────

    public function test_automation_platforms_subscribe_to_events_through_the_api(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok')]);
        $create = fn (array $scopes) => $this->postJson('/api/v1/developer/api-keys', ['name' => 'Zapier', 'scopes' => $scopes])->assertCreated()->json('data');
        $zapier = $create(['webhooks:manage', 'contacts:write']);
        $limited = $create(['contacts:read']);
        $this->app['auth']->forgetGuards();
        $api = fn (string $key) => $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);

        $api($limited['key'])->postJson('/api/public/v1/webhooks', ['url' => 'https://hooks.example.com/a', 'events' => ['contact.created']])->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
        $api($zapier['key'])->postJson('/api/public/v1/webhooks', ['url' => 'https://hooks.example.com/a', 'events' => ['nope']])->assertStatus(422);

        $hook = $api($zapier['key'])->postJson('/api/public/v1/webhooks', ['url' => 'https://hooks.example.com/a', 'events' => ['contact.created']])->assertCreated();
        $this->assertStringStartsWith('whsec_', (string) $hook->json('data.secret'));
        $api($zapier['key'])->getJson('/api/public/v1/webhooks')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
        $api($zapier['key'])->getJson('/api/public/v1/webhooks/samples?event=message.received')->assertOk()->assertJsonPath('data.0.event', 'message.received')->assertJsonPath('data.0.data.direction', 'inbound');

        // A real event reaches the subscribed address, signed.
        $api($zapier['key'])->postJson('/api/public/v1/contacts', ['phone' => '+971501112233', 'name' => 'New Lead'])->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.example.com/a' && $r['event'] === 'contact.created' && $r['data']['name'] === 'New Lead' && $r->hasHeader('X-Engage-Signature'));

        // Endpoints typed in the portal are not the API's to remove, and do not count against the API's allowance.
        $this->actingAsMember($this->owner);
        $portal = (string) $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://hooks.example.com/portal', 'events' => ['message.received']])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/developer/webhooks')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.source', 'api');
        $this->app['auth']->forgetGuards();
        $api($zapier['key'])->deleteJson("/api/public/v1/webhooks/{$portal}")->assertNotFound();

        $second = (string) $api($zapier['key'])->postJson('/api/public/v1/webhooks', ['url' => 'https://hooks.example.com/b', 'events' => ['message.received']])->assertCreated()->json('data.id');
        $api($zapier['key'])->deleteJson("/api/public/v1/webhooks/{$second}")->assertOk();
        $count = fn () => $this->tenantContext()->run($this->tenant, fn () => WebhookEndpoint::query()->where('source', 'api')->count());
        $this->assertSame(1, $count());

        // Revoking the key stops everything that was subscribed with it.
        $this->actingAsMember($this->owner);
        $this->deleteJson('/api/v1/developer/api-keys/'.$zapier['id'])->assertOk();
        $this->assertSame(0, $count());
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => WebhookEndpoint::query()->count()));
    }
}
