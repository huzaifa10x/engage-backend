<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Jobs\RecordAdClick;
use App\Domain\Conversions\Jobs\RecordConversion;
use App\Domain\Conversions\Jobs\SendConversionEvents;
use App\Domain\Conversions\Models\ConversionEvent;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Conversions\Services\ConversionRules;
use App\Domain\Conversions\Services\ConversionTriggers;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Conversions to Meta, 3a: the sender, and the "done when" list of the batch. */
final class ConversionSenderTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const DATASET = '1503923451234567';

    private const CLID = 'ARAkLkA8rmlFeiCktEJQ-QTwRiyYHAFDLMNDBH0CD3qpjd0HR4irJ6LEkR7JwFF4XvCF_mixedCASE';

    private Tenant $tenant;

    /** What Meta answers to POST /{dataset}/events next: ok | 503 | permission | bad. */
    private string $meta = 'ok';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->actingAsMember($this->addMember($this->tenant));
        $this->connectNumber($this->tenant);
        $this->tenantContext()->run($this->tenant, function () {
            ConversionsAccess::set($this->tenant, true);
            $waba = WabaAccount::query()->firstOrFail();
            $waba->forceFill(['events_permission' => true])->save();
            MetaDataset::query()->create(['waba_account_id' => $waba->id, 'waba_id' => $waba->waba_id, 'dataset_id' => self::DATASET, 'enabled' => true]);
            app(ConversionRules::class)->leadOnTag('booked');
            app(ConversionRules::class)->purchasesFromOrders();
        });

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $r) {
            if (str_contains($r->url(), self::DATASET.'/events')) {
                $bad = collect($r['data'])->contains(fn ($e) => ($e['user_data']['ctwa_clid'] ?? '') === 'BROKEN');

                return match (true) {
                    $this->meta === '503' => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503),
                    $this->meta === 'permission' => Http::response(['error' => ['message' => '(#200) Requires whatsapp_business_manage_events permission', 'type' => 'OAuthException', 'code' => 200]], 403),
                    $bad => Http::response(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'The ctwa_clid is not valid.', 'code' => 100]], 400),
                    default => Http::response(['events_received' => count($r['data']), 'messages' => [], 'fbtrace_id' => 'AbC']),
                };
            }

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.Str::random(8)]]]);
        }]);
    }

    private function fromAd(string $waId = '971501234567', string $clid = self::CLID): string
    {
        $referral = ['source_id' => '120210000000000001', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x', 'headline' => 'Free class', 'ctwa_clid' => $clid];
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['referral' => $referral], $waId, 'AE.'.$waId)))->assertOk();

        return (string) $this->tenantContext()->bypass(fn () => Contact::query()->where('wa_id', $waId)->value('id'));
    }

    private function send(): void
    {
        $this->artisan('engage:conversions:send')->assertSuccessful();
    }

    /** @return list<Request> */
    private function eventRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), self::DATASET.'/events'))->map(fn ($pair) => $pair[0])->values()->all();
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        return (array) $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->orderByDesc('created_at')->first());
    }

    private function order(string $contact, string $total = '349.50'): CommerceOrder
    {
        return $this->tenantContext()->run($this->tenant, fn () => CommerceOrder::query()->create([
            'integration_id' => (string) Str::uuid(), 'store_name' => 'Nova Store', 'external_id' => (string) random_int(1000, 9999), 'number' => '1001',
            'status' => 'processing', 'currency' => 'AED', 'total' => $total, 'paid' => true, 'paid_at' => now(), 'placed_at' => now(),
            'contact_id' => $contact, 'conversation_id' => Conversation::query()->where('contact_id', $contact)->value('id'), 'match' => CommerceOrder::LINK,
        ]));
    }

    public function test_an_ad_click_then_an_order_sends_exactly_one_purchase_with_the_aed_value_and_no_personal_data(): void
    {
        $contact = $this->fromAd();
        $order = $this->order($contact);
        $changed = fn () => $this->tenantContext()->run($this->tenant, fn () => ConversionTriggers::orderChanged($order->fresh()));
        $changed();
        $this->send();

        $requests = $this->eventRequests();
        $this->assertCount(1, $requests);
        $r = $requests[0];
        $this->assertSame('POST', $r->method());
        $this->assertSame('/v27.0/'.self::DATASET.'/events', parse_url($r->url(), PHP_URL_PATH));
        $this->assertSame('Bearer EAA-existing-token', $r->header('Authorization')[0], "the client's own token");
        $this->assertSame('engageX', $r['partner_agent']);
        $event = $r['data'][0];
        $this->assertSame([
            'event_name' => 'Purchase',
            'event_time' => $order->paid_at->getTimestamp(),
            'action_source' => 'business_messaging',
            'messaging_channel' => 'whatsapp',
            'user_data' => ['whatsapp_business_account_id' => '102290129340398', 'ctwa_clid' => self::CLID],
            'custom_data' => ['currency' => 'AED', 'value' => 349.5],
        ], array_diff_key($event, ['event_id' => true]));
        $body = json_encode($r->data());
        foreach (['971501234567', 'Sara', 'Nova Store', '+971'] as $personal) {
            $this->assertStringNotContainsString($personal, $body, 'no personal data');
        }
        $this->assertSame('sent', $this->event()['status']);
        $this->assertNotNull($this->event()['sent_at']);

        // The order is edited and the contact re-tagged: nothing new is sent.
        $this->tenantContext()->run($this->tenant, fn () => $order->forceFill(['status' => 'completed', 'total' => '399.00'])->save());
        $changed();
        $this->patchJson("/api/v1/contacts/{$contact}", ['tags' => ['booked']])->assertOk();  // first lead tag: a new LeadSubmitted, not a Purchase
        $this->patchJson("/api/v1/contacts/{$contact}", ['tags' => []])->assertOk();
        $this->patchJson("/api/v1/contacts/{$contact}", ['tags' => ['booked']])->assertOk();
        $this->send();
        $sent = collect($this->eventRequests())->flatMap(fn (Request $r) => $r['data'])->pluck('event_name')->all();
        $this->assertSame(['Purchase', 'LeadSubmitted'], $sent, 'one Purchase for the order, one lead for the tag, nothing repeated');
    }

    /**
     * Batch 6a, item 0: the contact timeline keeps only a hash of the click ID, while the raw ctwa_clid
     * stays on the ad click, where conversion reporting reads it. A Lead and a Purchase almost 7 days
     * after the click are still sent to Meta with the raw ID.
     */
    public function test_with_the_timeline_on_the_raw_click_id_is_kept_and_lead_and_purchase_are_sent_up_to_7_days_after_the_click(): void
    {
        $this->travel(-6)->days();
        $this->travel(-23)->hours();
        $contact = $this->fromAd();
        $this->travelBack();

        $stored = $this->tenantContext()->bypass(fn () => [
            DB::table('ad_clicks')->where('contact_id', $contact)->value('ctwa_clid'),
            DB::table('enquiries')->where('contact_id', $contact)->value('source_detail'),
            DB::table('timeline_events')->where('contact_id', $contact)->pluck('data')->implode(' '),
        ]);
        $this->assertSame(self::CLID, $stored[0], 'the raw click ID stays on the ad click record');
        $this->assertStringNotContainsString(self::CLID, (string) $stored[1], 'the enquiry keeps a hash only');
        $this->assertStringContainsString(substr(hash('sha256', self::CLID), 0, 32), (string) $stored[1]);
        $this->assertStringNotContainsString(self::CLID, $stored[2], 'the timeline never holds the click ID');

        $this->patchJson("/api/v1/contacts/{$contact}", ['tags' => ['booked']])->assertOk();
        $order = $this->order($contact);
        $this->tenantContext()->run($this->tenant, fn () => ConversionTriggers::orderChanged($order->fresh()));
        $this->send();

        $events = collect($this->eventRequests())->flatMap(fn (Request $r) => $r['data']);
        $this->assertSame(['LeadSubmitted', 'Purchase'], $events->pluck('event_name')->sort()->values()->all());
        $this->assertSame([self::CLID, self::CLID], $events->pluck('user_data.ctwa_clid')->all(), 'sent with the raw click ID');
        $this->assertSame(2, $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->where('status', 'sent')->count()));
        $types = $this->tenantContext()->bypass(fn () => DB::table('timeline_events')->where('contact_id', $contact)->pluck('type')->all());
        $this->assertContains('tag.added', $types);
        $this->assertContains('payment.paid', $types);
    }

    public function test_events_are_batched_per_dataset_up_to_1000_per_request(): void
    {
        $contact = $this->fromAd();
        $this->tenantContext()->run($this->tenant, function () use ($contact) {
            $conversation = Conversation::query()->where('contact_id', $contact)->value('id');
            $click = DB::table('ad_clicks')->value('id');
            $rows = [];
            for ($i = 0; $i < 1001; $i++) {
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'conversation_id' => $conversation, 'contact_id' => $contact, 'ad_click_id' => $click,
                    'rule_id' => (string) Str::uuid(), 'waba_id' => '102290129340398', 'event_name' => 'LeadSubmitted', 'event_time' => now()->subMinutes(2), 'status' => 'queued',
                    'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('conversion_events')->insert($chunk);
            }
        });

        $this->send();
        $this->send();
        $this->assertSame([1000, 1], array_map(fn (Request $r) => count($r['data']), $this->eventRequests()));
        $this->assertSame(1001, $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->where('status', 'sent')->count()));
    }

    public function test_an_event_older_than_6_days_is_expired_and_never_sent(): void
    {
        $this->travel(-6)->days();
        $this->travel(-1)->hours();
        $contact = $this->fromAd();
        $this->patchJson("/api/v1/contacts/{$contact}", ['tags' => ['booked']])->assertOk();
        $this->travelBack();

        $this->send();
        $this->assertSame([], $this->eventRequests());
        $this->assertSame('expired', $this->event()['status']);
        $this->assertStringContainsString('Not sent within 6 days', (string) $this->event()['error_reason']);
    }

    public function test_network_and_5xx_errors_are_retried_with_growing_gaps_and_content_errors_are_not(): void
    {
        $this->patchJson('/api/v1/contacts/'.$this->fromAd(), ['tags' => ['booked']])->assertOk();

        $this->meta = '503';
        $this->send();
        $this->assertSame(['queued', 1], [$this->event()['status'], $this->event()['attempts']]);
        $this->send(); // not due yet: no second call
        $this->assertCount(1, $this->eventRequests());
        $this->travel(2)->minutes();
        $this->send();
        $this->assertSame(['queued', 2], [$this->event()['status'], $this->event()['attempts']]);
        $this->travel(4)->minutes();
        $this->send(); // the gap grew to 5 minutes
        $this->assertCount(2, $this->eventRequests());

        $this->meta = 'ok';
        $this->travel(2)->minutes();
        $this->send();
        $this->assertSame('sent', $this->event()['status']);

        // A bad click ID in a batch: Meta refuses it; the good one still goes, the bad one fails with Meta's reason, no retry.
        $this->patchJson('/api/v1/contacts/'.$this->fromAd('971502222222', 'BROKEN'), ['tags' => ['booked']])->assertOk();
        $this->patchJson('/api/v1/contacts/'.$this->fromAd('971503333333', 'GOOD-CLICK'), ['tags' => ['booked']])->assertOk();
        $this->send();
        $this->travel(10)->minutes();
        $this->send();
        $byClick = $this->tenantContext()->bypass(fn () => DB::table('conversion_events as e')->join('ad_clicks as c', 'c.id', '=', 'e.ad_click_id')
            ->whereIn('c.ctwa_clid', ['BROKEN', 'GOOD-CLICK'])->pluck('e.status', 'c.ctwa_clid')->all());
        $this->assertSame(['BROKEN' => 'failed', 'GOOD-CLICK' => 'sent'], collect($byClick)->sortKeys()->all());
        $this->assertSame('Meta refused the event: The ctwa_clid is not valid.', $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->where('status', 'failed')->value('error_reason')));
    }

    public function test_events_that_fail_the_checks_are_never_sent(): void
    {
        $contact = $this->fromAd();
        $this->tenantContext()->run($this->tenant, fn () => ConversionTriggers::orderChanged($this->order($contact)->fresh()));
        $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->update(['currency' => null]));

        $this->send();
        $this->assertSame([], $this->eventRequests());
        $this->assertSame(['failed', 'A purchase needs its currency and value.'], [$this->event()['status'], $this->event()['error_reason']]);
    }

    public function test_removing_the_permission_skips_the_events_flags_reconnect_and_messaging_keeps_working(): void
    {
        $contact = $this->fromAd();
        $this->patchJson("/api/v1/contacts/{$contact}", ['tags' => ['booked']])->assertOk();

        $this->meta = 'permission';
        $this->send();
        $this->assertSame('skipped', $this->event()['status']);
        $this->assertStringContainsString('Reconnect it', (string) $this->event()['error_reason']);
        $waba = $this->tenantContext()->bypass(fn () => WabaAccount::query()->firstOrFail());
        $this->assertFalse($waba->events_permission);
        $this->assertNotNull($waba->permission_error_at, 'Channels shows Reconnect');

        // Messaging is not touched: a reply still goes out.
        $conversation = $this->tenantContext()->bypass(fn () => Conversation::query()->where('contact_id', $contact)->value('id'));
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['type' => 'text', 'body' => 'See you tomorrow!'])->assertSuccessful();

        // New conversions are skipped straight away, without calling Meta.
        $this->patchJson('/api/v1/contacts/'.$this->fromAd('971505555555'), ['tags' => ['booked']])->assertOk();
        $this->send();
        $this->assertCount(1, $this->eventRequests());
        $this->assertSame('skipped', $this->event()['status']);
    }

    public function test_everything_runs_on_the_maintenance_queue_after_the_message_is_saved(): void
    {
        Queue::fake([RecordAdClick::class, RecordConversion::class, SendConversionEvents::class]);
        $contact = $this->fromAd();
        $this->patchJson("/api/v1/contacts/{$contact}", ['tags' => ['booked']])->assertOk();
        $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'waba_id' => '102290129340398',
            'event_name' => 'LeadSubmitted', 'event_time' => now(), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]));
        $this->send();

        Queue::assertPushedOn('maintenance', RecordAdClick::class);
        Queue::assertPushedOn('maintenance', RecordConversion::class);
        Queue::assertPushedOn('maintenance', SendConversionEvents::class);
        $this->assertSame(ConversionEvent::QUEUED, $this->event()['status']);
    }
}
