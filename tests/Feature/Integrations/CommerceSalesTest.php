<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domain\Automation\Scheduler;
use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Engagement Plan v4, step D: the WooCommerce plugin connects with a workspace key, chat links get a
 * signed token, orders are matched to chats (link / review / phone / unavailable / none) and Sales
 * and Analytics › Sales & Revenue report only chat-link orders as chat revenue.
 */
final class CommerceSalesTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private string $storeId = '';

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        config(['app.url' => 'https://api.engage.test']);
        $this->tenant = $this->createTenant(['name' => 'Palm Store']);
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.O'.bin2hex(random_bytes(6))]]])]);
        $this->actingAsMember($this->owner);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    private function connectPlugin(string $site = 'https://www.palm.example/'): void
    {
        $key = (string) $this->postJson('/api/v1/integrations/woocommerce/plugin-key')->assertCreated()->json('data.key');
        $this->assertStringStartsWith('xewc_', $key);
        $r = $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => $key, 'site_url' => $site, 'name' => 'Palm &amp; Co', 'plugin_version' => '1.0.0', 'hpos' => true])->assertCreated();
        $this->storeId = (string) $r->json('data.store_id');
        $this->secret = (string) $r->json('data.secret');
        $this->assertSame('xl', $r->json('data.link_param'));
        $this->assertSame(14, $r->json('data.link_days'));
    }

    /** @param array<string, mixed> $payload */
    private function plugin(string $path, array $payload, ?int $ts = null, ?string $secret = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $ts ??= time();

        return $this->call('POST', "/api/integrations/woocommerce/{$this->storeId}/plugin/{$path}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENGAGE_TIMESTAMP' => (string) $ts,
            'HTTP_X_ENGAGE_SIGNATURE' => base64_encode(hash_hmac('sha256', $ts.'.'.$body, $secret ?? $this->secret, true)),
        ], $body);
    }

    /** @param array<string, mixed> $order */
    private function event(string $event, array $order, ?string $at = null): TestResponse
    {
        return $this->plugin('events', ['events' => [['event' => $event, 'occurred_at' => $at ?? now()->toIso8601String(), 'order' => $order]]]);
    }

    /** @return array<string, mixed> */
    private function order(array $overrides = []): array
    {
        return $overrides + [
            'id' => '7001', 'number' => '7001', 'status' => 'processing', 'currency' => 'AED', 'total' => '300.00', 'total_refunded' => '0.00',
            'date_created' => now()->subMinutes(5)->toIso8601String(), 'date_paid' => now()->subMinutes(4)->toIso8601String(), 'date_modified' => now()->subMinutes(4)->toIso8601String(),
            'billing' => ['first_name' => 'Sara', 'last_name' => 'Ahmed', 'phone' => '050 123 4567', 'country' => 'AE'],
            'line_items' => [['product_id' => 11, 'sku' => 'SH-1', 'name' => 'Linen shirt', 'quantity' => 2, 'total' => '200.00'], ['product_id' => 12, 'name' => 'Canvas tote', 'quantity' => 1, 'total' => '100.00']],
            'attribution' => ['source_type' => 'utm', 'utm_source' => 'whatsapp'],
        ];
    }

    private function customerWrites(string $waId = '971501234567', string $name = 'Sara Ahmed'): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue([], $waId, 'AE.'.$waId, $name)))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', $waId))->firstOrFail());
    }

    /** An agent sends a store link from the inbox; returns the token added to it. */
    private function agentSendsLink(Conversation $c, string $url = 'https://palm.example/product/linen-shirt?size=m'): string
    {
        $this->actingAsMember($this->owner);
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => "Here it is: {$url}. Anything else?"])->assertSuccessful();
        $body = (string) $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('conversation_id', $c->id)->where('direction', Message::OUTBOUND)->latest('created_at')->value('body'));
        $this->assertMatchesRegularExpression('~https://palm\.example/product/linen-shirt\?size=m&xl=[A-Za-z0-9]{24}\. Anything else\?~', $body);
        preg_match('/xl=([A-Za-z0-9]{24})/', $body, $m);

        return $m[1];
    }

    private function stored(string $externalId): CommerceOrder
    {
        return $this->tenantContext()->run($this->tenant, fn () => CommerceOrder::query()->where('external_id', $externalId)->firstOrFail());
    }

    // ── tests ──────────────────────────────────────────────────────────────────────────────

    public function test_the_plugin_connects_once_with_the_workspace_key_and_reports_health(): void
    {
        $this->connectPlugin();
        $integration = $this->tenantContext()->bypass(fn () => Integration::query()->where('public_id', $this->storeId)->firstOrFail());
        $this->assertSame('www.palm.example', $integration->external_id);
        $this->assertSame('Palm & Co', $integration->name);
        $this->assertTrue($integration->viaPlugin());

        // The key works once.
        $key = (string) $this->postJson('/api/v1/integrations/woocommerce/plugin-key')->assertCreated()->json('data.key');
        $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => $key, 'site_url' => 'https://other.example'])->assertCreated();
        $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => $key, 'site_url' => 'https://other.example'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'already used'));
        $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => 'xewc_nonsense', 'site_url' => 'https://x.example'])->assertStatus(422);

        // Unsigned, wrongly signed or old requests are refused.
        $this->plugin('heartbeat', ['plugin_version' => '1.0.0'], secret: 'wrong')->assertStatus(401);
        $this->plugin('heartbeat', ['plugin_version' => '1.0.0'], ts: time() - 600)->assertStatus(401);
        $this->plugin('heartbeat', ['plugin_version' => '1.0.0', 'wc_version' => '9.4.3', 'latest_order_at' => now()->subHours(30)->toIso8601String(), 'queue_pending' => 0])
            ->assertOk()->assertJsonPath('data.link_param', 'xl');

        // Malformed events are refused, never queued.
        $this->plugin('events', ['events' => [['event' => 'created', 'order' => ['number' => 'no id']]]])->assertStatus(202)->assertJsonPath('data.rejected', 1)->assertJsonPath('data.accepted', 0);

        $list = $this->getJson('/api/v1/integrations')->assertOk()->json('data.integrations');
        $store = collect($list)->firstWhere('id', $integration->id);
        $this->assertSame('plugin', $store['via']);
        // The store took an order 30 hours ago and nothing ever arrived: warned.
        $this->assertSame('warning', $store['health']['state']);
        $this->assertSame('no_events', $store['health']['warnings'][0]['code']);
    }

    public function test_a_chat_link_order_counts_as_chat_revenue_and_follows_refunds(): void
    {
        $this->connectPlugin();
        $c = $this->customerWrites();
        $token = $this->agentSendsLink($c);

        // A pending nudge for this person stops when they order.
        $nudge = $this->tenantContext()->run($this->tenant, fn () => app(Scheduler::class)->schedule('nudge', now()->addHour(), 'nudge:'.$c->id, $c, $c->contact, sendsMessage: true));

        $this->event('created', $this->order(['xl' => $token]))->assertStatus(202)->assertJsonPath('data.accepted', 1);
        $order = $this->stored('7001');
        $this->assertSame(CommerceOrder::LINK, $order->match);
        $this->assertSame($c->id, $order->conversation_id);
        $this->assertSame('300.00', $order->net);
        $this->assertEquals(['source_type' => 'utm', 'utm_source' => 'whatsapp'], $order->store_source); // WooCommerce's own source, kept apart
        $this->assertNotNull($nudge);
        $this->assertSame('cancelled', $this->tenantContext()->run($this->tenant, fn () => DB::table('scheduled_actions')->where('id', $nudge)->value('status')));

        // The same event again (a retry) is ignored.
        $at = now()->toIso8601String();
        $this->event('paid', $this->order(['xl' => $token]), $at)->assertStatus(202);
        $this->event('paid', $this->order(['xl' => $token]), $at)->assertStatus(202);
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => DB::table('commerce_order_events')->where('event', 'paid')->count()), 'a retried event is recorded once');

        // A partial refund lowers the revenue; an older state arriving late changes nothing.
        $this->event('refunded', $this->order(['xl' => $token, 'total_refunded' => '100.00', 'date_modified' => now()->toIso8601String()]))->assertStatus(202);
        $this->event('updated', $this->order(['xl' => $token, 'date_modified' => now()->subHour()->toIso8601String()]), now()->subHour()->toIso8601String())->assertStatus(202);
        $this->assertSame('200.00', $this->stored('7001')->net);

        $sales = $this->getJson('/api/v1/analytics/sales?days=30')->assertOk()->json('data');
        $this->assertSame('200.00', $sales['chat_link']['revenue']);
        $this->assertSame(1, $sales['chat_link']['orders']);
        $this->assertSame('0.00', $sales['phone']['revenue']);
        $this->assertSame('AED', $sales['currency']);
        $this->assertSame('Linen shirt', $sales['products'][0]['name']);
        $this->assertNotNull($sales['time_to_order']['median_hours']);

        // Filtered by the number chosen in the top bar (CN-02): the chat's number keeps it, another number has none.
        $this->getJson("/api/v1/growth/sales?phone_number_id={$c->phone_number_id}")->assertOk()->assertJsonCount(1, 'data');
        $other = (string) Str::uuid();
        $this->getJson("/api/v1/growth/sales?phone_number_id={$other}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/analytics/sales?phone_number_id={$other}")->assertOk()->assertJsonPath('data.number_filtered', true)->assertJsonPath('data.chat_link.orders', 0);
        $this->getJson("/api/v1/analytics/sales?phone_number_id={$c->phone_number_id}")->assertOk()->assertJsonPath('data.chat_link.orders', 1);

        // Growth Hub › Sales with the evidence, and the chat sidebar.
        $row = $this->getJson('/api/v1/growth/sales')->assertOk()->json('data.0');
        $this->assertSame('Order from a tracked chat link', $row['match_label']);
        $details = $this->getJson("/api/v1/growth/sales/{$row['id']}")->assertOk()->json('data');
        $this->assertSame('https://palm.example/product/linen-shirt?size=m', $details['link']['url']); // the token itself is never stored
        $this->assertSame(['updated', 'created', 'paid', 'refunded'], array_column($details['events'], 'event')); // in store time: the late, older state is first
        $this->getJson("/api/v1/contacts/{$c->contact_id}/orders")->assertOk()->assertJsonPath('data.total_spend.0.net', '200.00')->assertJsonPath('data.count', 1);

        // Cancelling removes the revenue.
        $this->event('cancelled', $this->order(['xl' => $token, 'status' => 'cancelled', 'date_modified' => now()->addMinute()->toIso8601String()]))->assertStatus(202);
        $this->assertSame('0.00', $this->stored('7001')->net);
    }

    public function test_orders_are_matched_by_link_review_phone_unavailable_or_not_at_all(): void
    {
        $this->connectPlugin();
        $sara = $this->customerWrites('971501234567', 'Sara Ahmed');
        $omar = $this->customerWrites('971509999999', 'Omar');
        $this->customerWrites('971507777777', 'Lina');
        $token = $this->agentSendsLink($sara);
        $omarToken = $this->agentSendsLink($omar);

        // The chat's token, but someone else's phone: kept for review, not counted.
        $this->event('created', $this->order(['id' => '1', 'number' => '1', 'xl' => $token, 'billing' => ['phone' => '+971 55 000 0000', 'country' => 'AE']]))->assertStatus(202);
        $this->assertSame(CommerceOrder::REVIEW, $this->stored('1')->match);
        $this->assertSame('0.00', $this->getJson('/api/v1/analytics/sales')->json('data.chat_link.revenue'));
        // The token is now used by nothing yet; once attached it attaches only this order.
        $id = $this->stored('1')->id;
        $this->postJson("/api/v1/growth/sales/{$id}/review", ['action' => 'attach'])->assertOk()->assertJsonPath('data.match', 'link');
        $this->event('created', $this->order(['id' => '2', 'number' => '2', 'xl' => $token]))->assertStatus(202);
        $this->assertSame(CommerceOrder::UNAVAILABLE, $this->stored('2')->match);
        $this->assertStringContainsString('already used for order #1', (string) $this->stored('2')->match_note);

        // Omar got a link but bought without it (another device): attribution unavailable.
        $this->event('created', $this->order(['id' => '3', 'number' => '3', 'date_created' => now()->addMinute()->toIso8601String(), 'billing' => ['phone' => '0509999999', 'country' => 'AE']]))->assertStatus(202);
        $this->assertSame(CommerceOrder::UNAVAILABLE, $this->stored('3')->match);
        // Lina is a contact but got no link: an order from a WhatsApp contact, never chat revenue.
        $this->event('created', $this->order(['id' => '4', 'number' => '4', 'billing' => ['phone' => '+971507777777']]))->assertStatus(202);
        $this->assertSame(CommerceOrder::PHONE, $this->stored('4')->match);
        // A stranger: a website order.
        $this->event('created', $this->order(['id' => '5', 'number' => '5', 'billing' => ['phone' => '+971521111111']]))->assertStatus(202);
        $this->assertSame(CommerceOrder::NONE, $this->stored('5')->match);
        // An expired link.
        $this->tenantContext()->run($this->tenant, fn () => DB::table('commerce_link_tokens')->where('contact_id', $omar->contact_id)->update(['expires_at' => now()->subDay()]));
        $this->event('created', $this->order(['id' => '6', 'number' => '6', 'xl' => $omarToken, 'billing' => ['phone' => '0509999999', 'country' => 'AE']]))->assertStatus(202);
        $this->assertSame(CommerceOrder::UNAVAILABLE, $this->stored('6')->match);
        $this->assertStringContainsString('expired', (string) $this->stored('6')->match_note);
        // A made-up token.
        $this->event('created', $this->order(['id' => '8', 'number' => '8', 'xl' => str_repeat('A', 24), 'billing' => ['phone' => '+971521111111']]))->assertStatus(202);
        $this->assertSame(CommerceOrder::UNAVAILABLE, $this->stored('8')->match);

        $sales = $this->getJson('/api/v1/analytics/sales')->assertOk()->json('data');
        $this->assertSame('300.00', $sales['chat_link']['revenue']);   // only the reviewed chat-link order
        $this->assertSame('300.00', $sales['phone']['revenue']);       // Lina, in its own column
        $counts = $this->getJson('/api/v1/growth/sales')->assertOk()->json('meta.counts');
        $this->assertEquals(['link' => 1, 'unavailable' => 4, 'phone' => 1, 'none' => 1, 'review' => 0], array_intersect_key($counts, array_flip(['link', 'unavailable', 'phone', 'none', 'review'])));
    }

    public function test_tokens_stay_in_their_workspace_and_links_to_other_sites_are_untouched(): void
    {
        $this->connectPlugin();
        $c = $this->customerWrites();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'See https://elsewhere.example/a and https://www.palm.example/b?xl=old123#top'])->assertSuccessful();
        $body = (string) $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('direction', Message::OUTBOUND)->latest('created_at')->value('body'));
        $this->assertStringContainsString('https://elsewhere.example/a and', $body);
        $this->assertMatchesRegularExpression('~https://www\.palm\.example/b\?xl=[A-Za-z0-9]{24}#top~', $body);
        preg_match('/xl=([A-Za-z0-9]{24})/', $body, $m);

        // Another workspace's store sends that token: not attached there.
        $other = $this->createTenant(['name' => 'Other']);
        $this->subscribe($other, 'pro');
        $member = $this->addMember($other);
        $this->actingAsMember($member);
        $key = (string) $this->postJson('/api/v1/integrations/woocommerce/plugin-key')->assertCreated()->json('data.key');
        $r = $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => $key, 'site_url' => 'https://other-shop.example'])->assertCreated();
        $this->storeId = (string) $r->json('data.store_id');
        $this->secret = (string) $r->json('data.secret');
        $this->event('created', $this->order(['id' => '9', 'xl' => $m[1]]))->assertStatus(202);
        $this->assertSame('unavailable', $this->tenantContext()->bypass(fn () => DB::table('commerce_orders')->where('tenant_id', $other->id)->where('external_id', '9')->value('match')));

        // The same store cannot be connected to two workspaces.
        $key2 = (string) $this->postJson('/api/v1/integrations/woocommerce/plugin-key')->assertCreated()->json('data.key');
        $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => $key2, 'site_url' => 'https://palm.example'])->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'another 10X Engage workspace'));
    }

    public function test_catch_up_names_missing_orders_and_a_paid_order_can_move_the_stage(): void
    {
        $this->connectPlugin();
        $stages = collect($this->postJson('/api/v1/crm/stages/suggested')->assertOk()->json('data'))->pluck('id', 'name')->all();
        $won = $stages['Won'] ?? array_values($stages)[count($stages) - 1];
        $integrationId = $this->tenantContext()->bypass(fn () => Integration::query()->where('public_id', $this->storeId)->value('id'));
        $this->patchJson("/api/v1/integrations/{$integrationId}", ['paid_stage_id' => $won, 'link_days' => 7])->assertOk()->assertJsonPath('data.link_days', 7);

        $c = $this->customerWrites();
        $token = $this->agentSendsLink($c);
        $this->event('created', $this->order(['xl' => $token]))->assertStatus(202);
        $this->assertSame($won, $this->tenantContext()->run($this->tenant, fn () => Contact::query()->find($c->contact_id)?->getAttribute('stage_id')));

        $modified = Carbon::parse($this->stored('7001')->store_updated_at);
        $resend = $this->plugin('reconcile', ['orders' => [
            ['id' => '7001', 'modified' => $modified->toIso8601String()],          // up to date
            ['id' => '7002', 'modified' => now()->toIso8601String()],              // never arrived
            ['id' => '7001x', 'modified' => 'not a date'],
        ]])->assertOk()->json('data.resend');
        $this->assertSame(['7002'], $resend);
        $resend = $this->plugin('reconcile', ['orders' => [['id' => '7001', 'modified' => $modified->copy()->addHour()->toIso8601String()]]])->assertOk()->json('data.resend');
        $this->assertSame(['7001'], $resend); // changed in the store since
    }

    public function test_the_plugin_zip_downloads_and_sales_need_the_plan(): void
    {
        $r = $this->get('/api/integrations/woocommerce/plugin.zip')->assertOk();
        $this->assertSame('application/zip', $r->headers->get('Content-Type'));

        $this->subscribe($this->tenant, 'starter');
        $this->getJson('/api/v1/analytics/sales')->assertOk()->assertJsonPath('data.locked', true);
        $this->getJson('/api/v1/growth/sales')->assertStatus(403);
    }
}
