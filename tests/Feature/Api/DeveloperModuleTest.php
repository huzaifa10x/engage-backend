<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\SystemRole;
use App\Domain\Developer\Jobs\DeliverWebhook;
use App\Domain\Developer\Models\ApiKey;
use App\Domain\Developer\Models\WebhookDelivery;
use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Notifications\WebhookEndpointDisabledNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class DeveloperModuleTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['name' => 'Palm Estates']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        // Customer endpoints in these tests live on a made-up public host.
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return str_ends_with($host, '.internal') ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        });
        Http::fake([
            'graph.facebook.com/*/messages*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.D'.bin2hex(random_bytes(6))]]]),
            'hooks.customer.example/*' => Http::response(['ok' => true]),
            'down.customer.example/*' => Http::response('boom', 500),
        ]);
    }

    /** @param list<string> $scopes */
    private function apiKey(array $scopes = ['messages:send', 'messages:read', 'contacts:read', 'contacts:write', 'templates:read']): string
    {
        $this->actingAsMember($this->owner);

        return (string) $this->postJson('/api/v1/developer/api-keys', ['name' => 'CRM sync', 'scopes' => $scopes])->assertCreated()->json('data.key');
    }

    /** A request from the customer's own server: an API key, no browser session. */
    private function api(string $key): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);
    }

    public function test_keys_are_shown_once_stored_hashed_scoped_and_revocable(): void
    {
        $this->actingAsMember($this->owner);
        $created = $this->postJson('/api/v1/developer/api-keys', ['name' => 'CRM sync', 'scopes' => ['contacts:read'], 'expires_in_days' => 90])->assertCreated();
        $plain = (string) $created->json('data.key');
        $this->assertMatchesRegularExpression('/^eng_live_[A-Za-z0-9]{40}$/', $plain);
        $this->postJson('/api/v1/developer/api-keys', ['name' => 'x', 'scopes' => ['everything']])->assertStatus(422);

        // Never stored or shown again.
        $row = $this->tenantContext()->bypass(fn () => ApiKey::query()->firstOrFail());
        $this->assertSame(hash('sha256', $plain), $row->key_hash);
        $list = $this->getJson('/api/v1/developer/api-keys')->assertOk()->assertJsonPath('data.0.prefix', substr($plain, 0, 13))->assertJsonPath('data.0.status', 'active');
        $this->assertStringNotContainsString(substr($plain, 13), (string) $list->getContent());

        // The key works for what it was given, and only that.
        $this->api($plain)->getJson('/api/public/v1/me')->assertOk()->assertJsonPath('data.workspace.name', 'Palm Estates')->assertHeader('X-RateLimit-Limit');
        $this->api($plain)->getJson('/api/public/v1/contacts')->assertOk();
        $this->api($plain)->postJson('/api/public/v1/contacts', ['phone' => '+971501112233'])->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
        $this->api($plain)->postJson('/api/public/v1/messages', ['to' => '+971501112233', 'type' => 'text', 'text' => 'Hi'])->assertStatus(403);
        // A key is not a portal login.
        $this->api($plain)->getJson('/api/v1/contacts')->assertStatus(401);
        $this->api($plain)->getJson('/api/v1/developer/api-keys')->assertStatus(401);

        $this->assertNotNull($this->tenantContext()->bypass(fn () => ApiKey::query()->firstOrFail()->last_used_at));

        // Wrong, missing, expired and revoked keys.
        $this->api('eng_live_'.str_repeat('a', 40))->getJson('/api/public/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'invalid_api_key');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/public/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'missing_api_key');
        $this->travel(91)->days();
        $this->api($plain)->getJson('/api/public/v1/me')->assertStatus(401);
        $this->travelBack();

        $this->actingAsMember($this->owner);
        $this->deleteJson("/api/v1/developer/api-keys/{$row->id}")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->api($plain)->getJson('/api/public/v1/me')->assertStatus(401);
    }

    public function test_a_key_only_ever_reaches_its_own_workspace_and_needs_the_plan(): void
    {
        $key = $this->apiKey();
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $foreign = $this->tenantContext()->run($other, fn () => Contact::query()->create(['wa_id' => '971509998877', 'name' => 'Not yours', 'source' => 'manual']));

        $this->api($key)->getJson("/api/public/v1/contacts/{$foreign->id}")->assertNotFound();
        $this->api($key)->getJson('/api/public/v1/contacts?phone=+971509998877')->assertOk()->assertJsonCount(0, 'data');
        $this->api($key)->patchJson("/api/public/v1/contacts/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();

        // Agents cannot manage keys; a plan without API access cannot create or use them.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->postJson('/api/v1/developer/api-keys', ['name' => 'x', 'scopes' => ['contacts:read']])->assertForbidden();

        $this->subscribe($this->tenant, 'starter');
        $this->api($key)->getJson('/api/public/v1/me')->assertStatus(403)->assertJsonPath('error.code', 'feature_not_available');
        $this->actingAsMember($this->owner);
        $this->postJson('/api/v1/developer/api-keys', ['name' => 'x', 'scopes' => ['contacts:read']])->assertForbidden();
    }

    public function test_the_api_manages_contacts_and_sends_messages_under_the_same_rules_as_the_portal(): void
    {
        $key = $this->apiKey();

        $id = $this->api($key)->postJson('/api/public/v1/contacts', ['phone' => '+971 50 111 2233', 'name' => 'Sara Ahmed', 'tags' => ['vip'], 'opted_in' => true])
            ->assertCreated()->assertJsonPath('data.phone', '+971501112233')->assertJsonPath('data.consent', 'opted_in')->json('data.id');
        $this->api($key)->postJson('/api/public/v1/contacts', ['phone' => '+971501112233'])->assertStatus(422); // already exists
        $this->api($key)->patchJson("/api/public/v1/contacts/{$id}", ['name' => 'Sara A.', 'attributes' => ['city' => 'Dubai']])->assertOk()->assertJsonPath('data.name', 'Sara A.');
        $this->api($key)->getJson('/api/public/v1/contacts?phone=971501112233')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tags.0', 'vip');

        // No open 24-hour window: plain text is refused, exactly as in the inbox.
        $this->api($key)->postJson('/api/public/v1/messages', ['contact_id' => $id, 'type' => 'text', 'text' => 'Hello'])->assertStatus(422)->assertJsonPath('error.code', 'window_closed');

        // The customer writes → the window opens → text is accepted, once per Idempotency-Key.
        $value = $this->inboundValue(waId: '971501112233');
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();
        $first = $this->api($key)->withHeaders(['Idempotency-Key' => 'order-1001'])->postJson('/api/public/v1/messages', ['to' => '+971501112233', 'type' => 'text', 'text' => 'Your order is on its way 🚚'])
            ->assertStatus(202)->assertJsonPath('data.direction', 'outbound')->assertJsonPath('data.origin', 'api')->assertJsonPath('data.text', 'Your order is on its way 🚚');
        $again = $this->api($key)->withHeaders(['Idempotency-Key' => 'order-1001'])->postJson('/api/public/v1/messages', ['to' => '+971501112233', 'type' => 'text', 'text' => 'Your order is on its way 🚚'])->assertStatus(202);
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        Http::assertSentCount(1);

        $this->api($key)->getJson('/api/public/v1/messages/'.$first->json('data.id'))->assertOk()->assertJsonPath('data.contact.phone', '+971501112233');
        $conversation = $this->api($key)->getJson('/api/public/v1/conversations')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->api($key)->getJson("/api/public/v1/conversations/{$conversation}/messages")->assertOk()->assertJsonCount(2, 'data');

        // Opt-out through the API is honoured by sending immediately.
        $this->api($key)->postJson("/api/public/v1/contacts/{$id}/opt-out", ['note' => 'Asked by phone'])->assertOk()->assertJsonPath('data.consent', 'opted_out');
        $this->api($key)->withHeaders(['Idempotency-Key' => 'order-1002'])->postJson('/api/public/v1/messages', ['contact_id' => $id, 'type' => 'text', 'text' => 'One more thing'])
            ->assertStatus(422)->assertJsonPath('error.code', 'contact_opted_out');

        $this->api($key)->getJson('/api/public/v1/phone-numbers')->assertOk()->assertJsonCount(1, 'data');
        $this->api($key)->getJson('/api/public/v1/templates')->assertOk();
    }

    public function test_webhooks_are_signed_delivered_logged_and_only_to_public_addresses(): void
    {
        $this->actingAsMember($this->owner);
        $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://db.internal/hook', 'events' => ['message.received']])->assertStatus(422); // private network
        $this->postJson('/api/v1/developer/webhooks', ['url' => 'ftp://hooks.customer.example/x', 'events' => ['message.received']])->assertStatus(422);
        $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://hooks.customer.example/engage', 'events' => ['nope']])->assertStatus(422);

        $created = $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://hooks.customer.example/engage', 'events' => ['message.received', 'contact.created', 'contact.opted_out']])->assertCreated();
        $secret = (string) $created->json('data.secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertStringNotContainsString($secret, (string) $this->getJson('/api/v1/developer/webhooks')->getContent());

        // A customer writes in → message.received (and the new contact) reach the endpoint, signed.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: '971501112233')))->assertOk();

        Http::assertSent(function (Request $r) use ($secret) {
            if (! str_contains($r->url(), 'hooks.customer.example') || $r->header('X-Engage-Event')[0] !== 'message.received') {
                return false;
            }
            [$t, $v1] = array_map(fn (string $p) => explode('=', $p, 2)[1], explode(',', $r->header('X-Engage-Signature')[0]));
            $body = json_decode($r->body(), true);

            return hash_equals(hash_hmac('sha256', $t.'.'.$r->body(), $secret), $v1)
                && $body['event'] === 'message.received' && $body['data']['direction'] === 'inbound' && $body['data']['contact']['phone'] === '+971501112233'
                && str_starts_with($body['id'], 'evt_') && $body['workspace_id'] === $this->tenant->id;
        });
        // Not subscribed to message.sent → nothing sent for it; subscribed events are logged as delivered.
        $log = $this->getJson('/api/v1/developer/deliveries')->assertOk();
        $events = collect($log->json('data'))->pluck('event');
        $this->assertContains('message.received', $events);
        $this->assertNotContains('message.sent', $events);
        $this->assertSame('delivered', $log->json('data.0.status'));
        $this->assertSame(200, $log->json('data.0.response_status'));

        // Test event and resend.
        $endpointId = $created->json('data.id');
        $this->postJson("/api/v1/developer/webhooks/{$endpointId}/test")->assertStatus(202)->assertJsonPath('data.status', 'delivered');
        $this->postJson('/api/v1/developer/deliveries/'.$log->json('data.0.id').'/resend')->assertStatus(202);

        // Another workspace sees none of it.
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/developer/webhooks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/developer/deliveries')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/developer/webhooks/{$endpointId}", ['status' => 'paused'])->assertNotFound();
    }

    public function test_failing_endpoints_are_retried_then_switched_off_and_owners_are_told(): void
    {
        Notification::fake();
        $this->actingAsMember($this->owner);
        $endpointId = $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://down.customer.example/hook', 'events' => ['contact.created']])->assertCreated()->json('data.id');

        // One failed event. (The test queue runs the scheduled retries straight away; in production
        // they are spread over about nine hours.) Every attempt is recorded, then it is failed for good.
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110001'])->assertCreated();
        $delivery = $this->tenantContext()->bypass(fn () => WebhookDelivery::query()->firstOrFail());
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(500, $delivery->response_status);
        $this->assertSame(count(DeliverWebhook::BACKOFF) + 1, $delivery->attempts);
        $endpoint = fn () => $this->tenantContext()->bypass(fn () => WebhookEndpoint::query()->findOrFail($endpointId));
        $this->assertSame('active', $endpoint()->status);
        $this->assertSame(6, $endpoint()->consecutive_failures);

        // Keep failing → switched off at 15 failures in a row, owners emailed once.
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110002'])->assertCreated();
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110003'])->assertCreated();
        $this->assertSame('disabled', $endpoint()->status);
        Notification::assertSentOnDemandTimes(WebhookEndpointDisabledNotification::class, 1);

        // Later events are not sent to a switched-off endpoint.
        $sent = count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'down.customer.example')));
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110099'])->assertCreated();
        $this->assertCount($sent, Http::recorded(fn (Request $r) => str_contains($r->url(), 'down.customer.example')));

        // Switching it back on clears the count.
        $this->patchJson("/api/v1/developer/webhooks/{$endpointId}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.consecutive_failures', 0);
    }

    public function test_the_address_guard_rejects_private_and_malformed_targets(): void
    {
        $guard = new UrlGuard;
        foreach (['https://127.0.0.1/x', 'https://10.1.2.3/x', 'https://169.254.169.254/latest/meta-data', 'https://[::1]/x', 'https://user:pass@example.com/x', 'not a url'] as $bad) {
            try {
                $guard->assertPublic($bad);
                $this->fail("{$bad} should have been rejected");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
