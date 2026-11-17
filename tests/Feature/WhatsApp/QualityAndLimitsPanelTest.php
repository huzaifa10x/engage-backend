<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Access\SystemRole;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\MemberNotification;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Batch 4, item 1: the "Quality and limits" panel per number, campaigns spread over the day, and
 * Meta's per-person marketing limit (131049) shown as such, never retried and never a failure.
 */
final class QualityAndLimitsPanelTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    private string $rating = 'GREEN';

    private string $metaLimit = 'TIER_1K';

    private ?int $sendError = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v27.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'big_sale', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Our big sale starts today.']]],
            ]]),
            'graph.facebook.com/v27.0/*/messages*' => fn () => $this->sendError !== null
                ? Http::response(['error' => ['code' => $this->sendError, 'message' => 'Message not delivered to maintain healthy ecosystem engagement']], 400)
                : Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.Q'.bin2hex(random_bytes(6))]]]),
            'graph.facebook.com/v27.0/106540352242922*' => fn () => Http::response([
                'id' => '106540352242922', 'display_phone_number' => '+971 58 549 6310', 'quality_rating' => $this->rating,
                'whatsapp_business_manager_messaging_limit' => $this->metaLimit,
            ]),
        ]);
        $this->postJson('/api/v1/templates/sync')->assertOk();
    }

    /** A webhook sent now (the shared helper dates them 1 January 2026). */
    private function now(string $body): string
    {
        return str_replace('"time":1767225600', '"time":'.now()->timestamp, $body);
    }

    private function panel(): array
    {
        return (array) $this->getJson("/api/v1/phone-numbers/{$this->number->id}/quality")->assertOk()->json('data');
    }

    /** @return list<string> */
    private function bell(): array
    {
        return $this->tenantContext()->bypass(fn () => MemberNotification::query()->where('membership_id', $this->owner->id)->where('type', 'number_quality')->pluck('title')->all());
    }

    /** @param list<string> $phones */
    private function campaignTo(array $phones): string
    {
        foreach ($phones as $i => $phone) {
            $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => "Guest {$i}", 'tags' => ['Sale']])->assertCreated();
        }
        $this->tenantContext()->run($this->tenant, fn () => Contact::query()->update(['consent_state' => 'opted_in']));
        $template = (string) collect($this->getJson('/api/v1/templates')->json('data'))->firstWhere('name', 'big_sale')['id'];
        $id = (string) $this->postJson('/api/v1/campaigns', ['name' => 'Big sale', 'phone_number_id' => $this->number->id, 'template_id' => $template, 'audience_tag' => 'Sale'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        return $id;
    }

    public function test_the_panel_shows_rating_limit_used_and_campaign_pace(): void
    {
        $this->tenantContext()->run($this->tenant, fn () => WabaAccount::query()->update(['meta_business_id' => '5550001']));
        $this->postJson('/api/v1/messages', ['phone_number_id' => $this->number->id, 'to' => '+971502220001', 'type' => 'template',
            'template' => ['name' => 'big_sale', 'language' => 'en']])->assertStatus(202);

        $panel = $this->panel();
        $this->assertSame('GREEN', $panel['quality_rating']);
        $this->assertSame('TIER_1K', $panel['messaging_limit_tier']);
        $this->assertSame(1000, $panel['limit']);
        $this->assertSame(1, $panel['used']);
        $this->assertSame(999, $panel['remaining']);
        $this->assertSame(25, $panel['campaign_hourly_share']);
        $this->assertSame(250, $panel['campaign_hourly_cap']);
        $this->assertNull($panel['warning']);
        $this->assertSame([], $panel['history']);

        // An agent without access to Channels does not see it.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson("/api/v1/phone-numbers/{$this->number->id}/quality")->assertForbidden();
    }

    public function test_a_quality_webhook_updates_the_panel_at_once_red_pauses_campaigns_and_alerts_owners(): void
    {
        Notification::fake();
        $campaign = $this->tenantContext()->run($this->tenant, fn () => Campaign::query()->create(['name' => 'Big sale', 'phone_number_id' => $this->number->id,
            'template_name' => 'big_sale', 'template_language' => 'en', 'template_category' => 'MARKETING', 'status' => 'sending']));

        // Meta flags the number and lowers its limit; the re-read 30 seconds later finds it Red.
        $this->rating = 'RED';
        $this->metaLimit = 'TIER_250';
        $this->postWebhook($this->now($this->webhookBody('phone_number_quality_update', ['display_phone_number' => '971585496310', 'event' => 'DOWNGRADE', 'current_limit' => 'TIER_250'])))->assertOk();
        $this->postWebhook($this->now($this->webhookBody('phone_number_quality_update', ['display_phone_number' => '971585496310', 'event' => 'FLAGGED', 'current_limit' => 'TIER_250'])))->assertOk();

        $panel = $this->panel();
        $this->assertSame('RED', $panel['quality_rating']);
        $this->assertSame('TIER_250', $panel['messaging_limit_tier']);
        $this->assertSame(63, $panel['campaign_hourly_cap']); // 25% of 250, rounded up
        $this->assertSame('Meta flagged the number for low quality', $panel['warning']['title']);
        $titles = array_column($panel['history'], 'title');
        $this->assertContains('Quality rating: Green → Red', $titles);
        $this->assertContains('Messaging limit: 1,000 people a day → 250 people a day', $titles);
        $this->assertContains('Meta flagged the number for low quality', $titles);

        $this->assertSame('paused', $this->tenantContext()->bypass(fn () => $campaign->fresh()->status->value));
        $this->assertContains('Quality rating dropped to Red on +971 58 549 6310', $this->bell());

        // Meta lifts the flag: no current warning any more, the history keeps it.
        $this->postWebhook($this->now($this->webhookBody('phone_number_quality_update', ['display_phone_number' => '971585496310', 'event' => 'UNFLAGGED', 'current_limit' => 'TIER_250'])))->assertOk();
        $this->assertNull($this->panel()['warning']);

        // Older than 30 days: gone from the panel.
        $this->travel(31)->days();
        $this->assertSame([], $this->panel()['history']);
    }

    public function test_yellow_warns_owners_without_pausing_and_the_hourly_refresh_records_it(): void
    {
        $campaign = $this->tenantContext()->run($this->tenant, fn () => Campaign::query()->create(['name' => 'Big sale', 'phone_number_id' => $this->number->id,
            'template_name' => 'big_sale', 'template_language' => 'en', 'template_category' => 'MARKETING', 'status' => 'sending']));
        $this->rating = 'YELLOW';
        Artisan::call('engage:whatsapp:refresh-limits');

        $this->assertSame('YELLOW', $this->panel()['quality_rating']);
        $this->assertSame('Quality rating: Green → Yellow', $this->panel()['history'][0]['title']);
        $this->assertSame(['Quality rating dropped to Yellow on +971 58 549 6310'], $this->bell());
        $this->assertSame('sending', $this->tenantContext()->bypass(fn () => $campaign->fresh()->status->value));
    }

    public function test_account_alerts_show_as_a_warning(): void
    {
        $this->postWebhook($this->now($this->webhookBody('account_alerts', ['entity_type' => 'PHONE_NUMBER', 'entity_id' => '106540352242922',
            'alert_info' => ['alert_severity' => 'WARNING', 'alert_status' => 'ACTIVE', 'alert_type' => 'INCREASED_BLOCK_RATE',
                'alert_description' => 'More people than usual blocked this number in the last 7 days.']])))->assertOk();

        $warning = $this->panel()['warning'];
        $this->assertSame('Meta alert: Increased block rate', $warning['title']);
        $this->assertSame('More people than usual blocked this number in the last 7 days.', $warning['detail']);
    }

    public function test_a_large_campaign_is_spread_over_hours(): void
    {
        // A daily limit of 40 people: at most 10 an hour for one campaign (25%).
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['messaging_limit_tier' => '40'])->save());
        $this->metaLimit = '40';
        $id = $this->campaignTo(array_map(fn ($i) => '+9715022'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), range(1, 25)));

        $campaign = $this->getJson("/api/v1/campaigns/{$id}")->assertOk()->assertJsonPath('data.status', 'sending')
            ->assertJsonPath('data.wait_reason', 'drip')->assertJsonPath('data.stats.sent', 10)->assertJsonPath('data.stats.pending', 15)->json('data');
        $this->assertGreaterThanOrEqual(now()->addMinutes(59)->getTimestamp(), strtotime((string) $campaign['next_batch_at']));

        // Nothing more within the hour, however often the scheduler runs.
        Artisan::call('engage:campaigns:dispatch');
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 10);
        $this->travel(61)->minutes();
        Artisan::call('engage:campaigns:dispatch');
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 20);

        // The share is set per workspace.
        $this->putJson('/api/v1/compliance/settings', ['campaign_hourly_share' => 50])->assertOk()->assertJsonPath('data.campaign_hourly_share', 50);
        $this->putJson('/api/v1/compliance/settings', ['campaign_hourly_share' => 2])->assertUnprocessable();

        // 10,000 recipients on a 10K limit: 2,500 an hour, so four hours, not one burst.
        $this->assertSame(2500, ComplianceSettings::for(null)->campaignHourlyCap(10000));
    }

    public function test_the_per_person_marketing_limit_is_labelled_not_retried_and_not_a_failure(): void
    {
        $this->sendError = 131049;
        $id = $this->campaignTo(['+971502220001', '+971502220002']);

        Http::assertSentCount(1 /* template sync */ + 2 /* one try each, no retry */ + 0);
        $messages = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('campaign_id', $id)->get());
        $this->assertCount(2, $messages);
        $this->assertSame(['Not delivered: Meta’s per-person marketing limit'], $messages->pluck('error_title')->unique()->values()->all());

        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.marketing_limit', 2)->assertJsonPath('data.stats.failed', 0);
        $this->getJson('/api/v1/analytics/overview?days=7')->assertOk()->assertJsonPath('data.totals.failed', 0);

        // The same refusal arriving later in a status webhook gets the same label.
        $this->sendError = null;
        $this->postJson('/api/v1/messages', ['phone_number_id' => $this->number->id, 'to' => '+971502220003', 'type' => 'template',
            'template' => ['name' => 'big_sale', 'language' => 'en']])->assertStatus(202);
        $wamid = (string) $this->tenantContext()->run($this->tenant, fn () => Message::query()->whereNull('campaign_id')->latest()->value('wamid'));
        $this->postWebhook($this->now($this->webhookBody('messages', ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'],
            'statuses' => [['id' => $wamid, 'status' => 'failed', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '971502220003',
                'errors' => [['code' => 131049, 'title' => 'This message was not delivered to maintain healthy ecosystem engagement.']]]]])))->assertOk();
        $this->assertSame('Not delivered: Meta’s per-person marketing limit',
            $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('wamid', $wamid)->value('error_title')));
    }
}
