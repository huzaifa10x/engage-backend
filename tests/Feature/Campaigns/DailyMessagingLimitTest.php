<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Messaging\Services\MessagingLimits;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Meta's daily messaging limit, enforced by the platform: a business starts conversations with at
 * most N different people per moving 24 hours across all of its numbers. Campaigns send up to the
 * limit and wait for room; one-off sends past it are refused; a raised limit is used at once.
 */
final class DailyMessagingLimitTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    private string $template;

    /** @var array<string, string> phone → contact id */
    private array $contacts = [];

    /** Answer for GET /<PHONE_NUMBER_ID> (the limit Meta reports). */
    private string $metaLimit = 'TIER_250';

    private bool $metaRejects = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->tenantContext()->run($this->tenant, function (): void {
            WabaAccount::query()->update(['meta_business_id' => '5550001']);
            $this->number->forceFill(['messaging_limit_tier' => '3'])->save(); // a limit of 3 people per 24 hours
        });
        $this->actingAsMember($this->owner);

        Http::fake([
            'graph.facebook.com/v26.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900002', 'name' => 'class_reminder', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Reminder: your class is tomorrow.']]],
            ]]),
            'graph.facebook.com/v26.0/*/messages*' => fn () => $this->metaRejects
                ? Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)
                : Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.L'.bin2hex(random_bytes(6))]]]),
            'graph.facebook.com/v26.0/106540352242922*' => fn () => Http::response([
                'id' => '106540352242922', 'display_phone_number' => '+971 58 549 6310', 'quality_rating' => 'GREEN',
                'whatsapp_business_manager_messaging_limit' => $this->metaLimit, 'messaging_limit_tier' => 'TIER_1K',
            ]),
        ]);
        $this->postJson('/api/v1/templates/sync')->assertOk();
        $this->template = (string) collect($this->getJson('/api/v1/templates')->json('data'))->firstWhere('name', 'class_reminder')['id'];

        for ($i = 1; $i <= 5; $i++) {
            $phone = '+97150222000'.$i;
            $this->contacts[$phone] = (string) $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => "Guest {$i}", 'tags' => ['Class']])->assertCreated()->json('data.id');
        }
    }

    private function template(string $phone): TestResponse
    {
        return $this->postJson('/api/v1/messages', [
            'phone_number_id' => $this->number->id, 'to' => $phone, 'type' => 'template', 'template' => ['name' => 'class_reminder', 'language' => 'en'],
        ]);
    }

    private function counted(): int
    {
        return $this->tenantContext()->run($this->tenant, fn () => DB::table('business_initiations')->count());
    }

    private function campaign(): string
    {
        $id = (string) $this->postJson('/api/v1/campaigns', [
            'name' => 'Class reminder', 'phone_number_id' => $this->number->id, 'template_id' => $this->template, 'audience_tag' => 'Class',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        return $id;
    }

    public function test_one_off_templates_stop_at_the_limit_and_the_same_person_counts_once(): void
    {
        $this->template('+971502220001')->assertStatus(202);
        $this->template('+971502220001')->assertStatus(202); // the same person again: still one
        $this->template('+971502220002')->assertStatus(202);
        $this->template('+971502220003')->assertStatus(202);
        $this->assertSame(3, $this->counted());

        $refused = $this->template('+971502220004')->assertStatus(429)->assertJsonPath('error.code', 'daily_limit_reached');
        $this->assertStringContainsString('limit of 3 new conversations per 24 hours', (string) $refused->json('error.message'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['to'] === '971502220004');
        // Someone already counted can still be messaged.
        $this->template('+971502220002')->assertStatus(202);

        // Someone who wrote in the last 24 hours (open window) does not count against the limit.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: '971502220005', bsuid: null, name: 'Guest 5')))->assertOk();
        $this->template('+971502220005')->assertStatus(202);
        $this->assertSame(3, $this->counted());

        // 24 hours later the window has moved on: room again.
        $this->travel(25)->hours();
        $this->template('+971502220004')->assertStatus(202);
    }

    public function test_a_message_meta_refuses_gives_its_place_back(): void
    {
        $this->metaRejects = true;
        $this->template('+971502220001')->assertStatus(202);
        $this->assertSame(0, $this->counted());
    }

    public function test_a_campaign_sends_up_to_the_limit_waits_and_carries_on_when_meta_raises_it(): void
    {
        $this->getJson("/api/v1/campaigns/audience?audience_tag=Class&template_id={$this->template}&phone_number_id={$this->number->id}")->assertOk()
            ->assertJsonPath('data.eligible', 5)->assertJsonPath('data.messaging_limit', 3)
            ->assertJsonPath('data.messaging_used', 0)->assertJsonPath('data.messaging_remaining', 3);

        $id = $this->campaign();
        $campaign = $this->getJson("/api/v1/campaigns/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'sending')->assertJsonPath('data.wait_reason', 'daily_limit')
            ->assertJsonPath('data.stats.sent', 3)->assertJsonPath('data.stats.pending', 2)->json('data');
        $this->assertGreaterThan(now()->addHours(23)->getTimestamp(), strtotime((string) $campaign['next_batch_at']));
        Http::assertSentCount(4 /* template sync + 3 messages */);

        // Nothing more goes out while the limit is used up, however often the scheduler runs.
        Artisan::call('engage:campaigns:dispatch');
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 3);
        $this->getJson("/api/v1/campaigns/audience?audience_tag=Class&template_id={$this->template}&phone_number_id={$this->number->id}")
            ->assertJsonPath('data.messaging_used', 3)->assertJsonPath('data.messaging_remaining', 0);

        // Meta raises the limit (the hourly refresh reads it): the campaign continues right away.
        $this->metaLimit = 'TIER_2K';
        Artisan::call('engage:whatsapp:refresh-limits');
        $this->assertSame('TIER_2K', $this->tenantContext()->run($this->tenant, fn () => PhoneNumber::query()->findOrFail($this->number->id)->messaging_limit_tier));
        Artisan::call('engage:campaigns:dispatch');
        $this->getJson("/api/v1/campaigns/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.stats.sent', 5)->assertJsonPath('data.wait_reason', null);
    }

    public function test_a_waiting_campaign_continues_by_itself_once_the_window_moves_on(): void
    {
        $id = $this->campaign();
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 3)->assertJsonPath('data.wait_reason', 'daily_limit');

        $this->travel(24)->hours();
        $this->travel(2)->minutes();
        Artisan::call('engage:campaigns:dispatch');
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'completed')->assertJsonPath('data.stats.sent', 5);
    }

    public function test_the_limit_is_shared_by_every_number_of_the_business(): void
    {
        // Another WABA of the same business portfolio, with its own number.
        $second = $this->connectNumber($this->tenant, '102290129340399', '106540352242923', '+971 58 000 0002');
        $this->tenantContext()->run($this->tenant, function () use ($second): void {
            WabaAccount::query()->whereKey($second->waba_account_id)->update(['meta_business_id' => '5550001']);
            PhoneNumber::query()->whereKey($second->id)->update(['messaging_limit_tier' => '3']);
        });

        $this->template('+971502220001')->assertStatus(202);
        $this->template('+971502220002')->assertStatus(202);

        // Both numbers draw from one pool of 3: one left for the second number too.
        $this->tenantContext()->run($this->tenant, function () use ($second): void {
            $limits = app(MessagingLimits::class);
            $fresh = PhoneNumber::query()->findOrFail($second->id);
            $this->assertSame('biz:5550001', $limits->keyFor($fresh));
            $this->assertSame($limits->keyFor($this->number), $limits->keyFor($fresh));
            $this->assertSame(1, $limits->remaining($fresh));
        });
    }

    public function test_the_limit_is_read_from_meta_once_a_day_on_the_first_visit_and_from_webhooks(): void
    {
        $this->metaLimit = 'TIER_10K';
        $this->getJson('/api/v1/me')->assertOk();
        $number = fn () => $this->tenantContext()->run($this->tenant, fn () => PhoneNumber::query()->findOrFail($this->number->id));
        $this->assertSame('TIER_10K', $number()->messaging_limit_tier);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '106540352242922?') && str_contains(urldecode($r->url()), 'whatsapp_business_manager_messaging_limit'));

        // Later the same day: no second read.
        $this->metaLimit = 'TIER_100K';
        $this->getJson('/api/v1/me')->assertOk();
        $this->assertSame('TIER_10K', $number()->messaging_limit_tier);

        // Meta's limit webhook applies straight away.
        $this->postWebhook($this->webhookBody('business_capability_update', ['max_daily_conversations_per_business' => 100000]))->assertOk();
        $this->assertSame('TIER_100K', $number()->messaging_limit_tier);
        $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->assertOk()->assertJsonPath('data.daily_limit.limit', 100000);

        // Unlimited (the webhook, then the confirming read from Meta): no counting at all.
        $this->metaLimit = 'TIER_UNLIMITED';
        $this->postWebhook($this->webhookBody('business_capability_update', ['max_daily_conversations_per_business' => 'UNLIMITED']))->assertOk();
        $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->assertJsonPath('data.daily_limit.limit', null);
        $this->template('+971502220001')->assertStatus(202);
        $this->assertSame(0, $this->counted());
    }

    public function test_tiers_are_read_as_meta_writes_them(): void
    {
        $this->assertSame(250, MessagingLimits::parseTier('TIER_250'));
        $this->assertSame(2000, MessagingLimits::parseTier('TIER_2K'));
        $this->assertSame(100000, MessagingLimits::parseTier(100000));
        $this->assertSame(MessagingLimits::UNLIMITED, MessagingLimits::parseTier('TIER_UNLIMITED'));
        $this->assertNull(MessagingLimits::parseTier('SOMETHING_NEW'));
        $this->assertSame('TIER_2K', MessagingLimits::tierName(2000));
        $this->assertSame('TIER_UNLIMITED', MessagingLimits::tierName('UNLIMITED'));

        // Nothing known yet: the lowest tier applies.
        $this->tenantContext()->run($this->tenant, function (): void {
            PhoneNumber::query()->whereKey($this->number->id)->update(['messaging_limit_tier' => null]);
            $this->assertSame(MessagingLimits::DEFAULT_LIMIT, app(MessagingLimits::class)->limitFor(PhoneNumber::query()->findOrFail($this->number->id)));
        });
        $this->assertTrue(Campaign::query()->getModel()->isFillable('wait_reason'));
    }
}
