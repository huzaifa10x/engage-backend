<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Engine\BotInput;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Website\Reference;
use App\Domain\Growth\Website\WebsiteSource;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Growth Hub R1, website half (Developer Handover steps 5–11; Technical Specification v1.2 §1, §3,
 * §8, §11 QA matrix): site setup, the public collector, registration-gated matching, the pending
 * queue, "Website · details unavailable", Google Ads attribution, privacy by role and chatbot text.
 */
final class WebsiteTrackingTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private PhoneNumber $number;

    /** @var array<string, mixed> */
    private array $site;

    private const ORIGIN = 'https://www.clinic-example.ae';

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'growth');
        $this->number = $this->connectNumber($this->tenant);
        $this->tenantContext()->bypass(function () {
            $settings = (array) ($this->tenant->settings ?? []);
            $settings['growth'] = ['enabled' => true, 'started_at' => now()->subMinute()->toIso8601String()];
            $this->tenant->forceFill(['settings' => $settings])->save();
        });
        GrowthSettings::forget();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $this->site = $this->postJson('/api/v1/growth/sites', ['domain' => 'https://www.Clinic-Example.ae/contact', 'connected_phone_ids' => [$this->number->id]])
            ->assertCreated()->json('data');
    }

    /** @param array<string, mixed> $overrides */
    private function beacon(array $overrides = [], string $origin = self::ORIGIN, string $ua = self::UA): TestResponse
    {
        $body = array_merge([
            'site_key' => $this->site['site_key'],
            'config_version' => 1,
            'event_id' => (string) Str::uuid(),
            'reference' => 'XG-7PK9M2Q8TD',
            'mapping_id' => $this->site['numbers'][0]['mapping_id'],
            'page_path' => '/pricing?secret=1',
            'language' => 'en',
            'campaign' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'october_uae', 'gclid' => 'Cj0KCQjwGCLIDvalue'],
            'collection_mode' => 'click_only',
            'source_of_campaign_params' => 'current_page',
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);

        return $this->call('POST', '/v1/c', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => $origin, 'HTTP_USER_AGENT' => $ua], (string) json_encode($body));
    }

    private function message(string $text, string $waId = '971500000001', ?int $at = null, array $extra = []): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text], 'timestamp' => (string) ($at ?? now()->timestamp)] + $extra, $waId, 'AE.'.$waId, 'Visitor '.$waId)))->assertOk();
    }

    private function contact(string $waId): Contact
    {
        return $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', $waId)->sole());
    }

    public function test_the_site_has_a_snippet_numbers_and_public_config(): void
    {
        $this->assertSame('www.clinic-example.ae', $this->site['domain']);
        $this->assertStringContainsString('/sdk/v1/e.min.js?key='.$this->site['site_key'], $this->site['snippet']);
        $this->assertSame('971585496310', $this->site['numbers'][0]['public_number']);
        $this->assertSame('not_installed', $this->site['health']['tracking_status']);

        $config = $this->get('/v1/config?site_key='.$this->site['site_key'], ['Origin' => self::ORIGIN]);
        $config->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
        $this->assertTrue($config->json('enabled'));
        $this->assertTrue($config->json('session_landing_params_enabled'), 'multi-page memory on by default');
        $this->assertSame('click_only', $config->json('mode'));
        $this->assertContains('clinic-example.ae', $config->json('allowed_hosts'));
        $this->assertSame("Hi, I'd like to know more about your services. (Ref: {reference})", $config->json('templates.en'));
        $this->get('/v1/config?site_key='.$this->site['site_key'], ['Origin' => 'https://copied-elsewhere.com'])->assertForbidden();
        $this->get('/sdk/v1/e.min.js?key='.$this->site['site_key'])->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        // The tracker loaded: the site card says so.
        $this->assertSame('awaiting_verification', $this->getJson("/api/v1/growth/sites/{$this->site['id']}/health")->json('data.tracking_status'));
    }

    public function test_open_my_site_verifies_only_on_the_site_itself(): void
    {
        $url = $this->postJson("/api/v1/growth/sites/{$this->site['id']}/verification-session")->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://www.clinic-example.ae/?xg_verify=', $url);
        $challenge = substr($url, strpos($url, '=') + 1);
        $post = fn (string $origin, string $c) => $this->call('POST', '/v1/v', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => $origin], (string) json_encode(['site_key' => $this->site['site_key'], 'challenge' => $c]));

        $post('https://another-site.com', $challenge)->assertForbidden();   // SITE-02: copied snippet elsewhere
        $post(self::ORIGIN, 'wrong-challenge')->assertStatus(422);            // SITE-03
        $post(self::ORIGIN, $challenge)->assertOk()->assertJsonPath('verified', true);
        $this->assertNotNull($this->getJson("/api/v1/growth/sites/{$this->site['id']}")->json('data.verified_at'));
    }

    public function test_click_registration_is_idempotent_and_rejects_bad_input(): void
    {
        $event = (string) Str::uuid();
        $this->beacon(['event_id' => $event])->assertCreated()->assertJsonPath('status', 'registered');
        $this->beacon(['event_id' => $event])->assertOk();                                             // NAV-14 same event retry
        $this->beacon(['event_id' => $event, 'page_path' => '/other'])->assertStatus(409)->assertJsonPath('error', 'event_id_conflict'); // NAV-15
        $this->beacon()->assertStatus(409)->assertJsonPath('error', 'reference_collision');            // NAV-13 same code, other event
        $this->beacon(['reference' => 'XG-7PK9M2Q8T0'])->assertStatus(422);                           // alphabet
        $this->beacon(['mapping_id' => (string) Str::uuid(), 'reference' => 'XG-AAAAAAAAAA'])->assertForbidden(); // NAV-16 unknown mapping
        $this->beacon(['reference' => 'XG-BBBBBBBBBB'], 'https://evil.example')->assertForbidden();    // wrong origin
        $this->beacon(['extra' => 'x', 'reference' => 'XG-CCCCCCCCCC'])->assertStatus(422);             // strict keys
        $this->beacon(['reference' => 'XG-DDDDDDDDDD'], self::ORIGIN, 'Googlebot/2.1 (+http://www.google.com/bot.html)')
            ->assertStatus(202)->assertJsonPath('status', 'filtered');                                // bot excluded

        $this->tenantContext()->run($this->tenant, function () {
            $this->assertSame(1, DB::table('growth_references')->count(), 'a rejected or filtered tap leaves no redeemable code');
            $this->assertSame('/pricing', DB::table('growth_click_events')->where('status', 'registered')->value('page_path'), 'no query string kept');
        });
    }

    public function test_a_google_ads_tap_followed_by_send_is_a_reference_matched_lead(): void
    {
        $this->beacon()->assertCreated();
        $this->message("Hi, I'd like to know more about your services. (Ref: XG-7PK9M2Q8TD)");

        $contact = $this->contact('971500000001');
        $this->assertSame('google_ads', $contact->acquisition_original['source']);
        $this->assertSame('website_reference', $contact->acquisition_original['type']);
        $this->assertSame('october_uae', $contact->acquisition_original['campaign']);
        $this->assertSame('/pricing', $contact->acquisition_original['landing_path']);
        $this->assertSame('Cj0KCQjwGCLIDvalue', $contact->acquisition_original['click_ids']['gclid'], 'gclid kept on the lead for later conversion uploads');

        // Owner: Leads shows it as Reference matched with the campaign; the click ID never leaves the server.
        $r = $this->getJson('/api/v1/growth/leads')->assertOk();
        $this->assertSame('Reference matched · Google Ads', $r->json('data.0.contact.acquisition.label'));
        $this->assertSame('october_uae', $r->json('data.0.contact.acquisition.source.campaign'));
        $this->assertStringNotContainsString('Cj0KCQjwGCLIDvalue', (string) $r->getContent());
        $this->assertSame(1, $this->getJson('/api/v1/growth/leads?source=google_ads')->json('meta.counts.all'));
        $this->assertSame(1, $this->getJson('/api/v1/growth/leads?status=ref')->json('meta.counts.all'));
        $this->assertSame(1, $this->getJson('/api/v1/growth/leads/summary')->json('data.websites'));

        // Lead details › Evidence: where and how, masked reference, which click ID came — never its value.
        $evidence = $this->getJson("/api/v1/growth/leads/{$contact->id}/evidence")->assertOk();
        $this->assertSame('Google Ads', $evidence->json('data.0.label'));
        $this->assertSame(['gclid'], $evidence->json('data.0.details.click_ids_sent'));
        $this->assertSame('www.clinic-example.ae', $evidence->json('data.0.details.site'));
        $this->assertSame('XG-7•••8TD', $evidence->json('data.0.details.reference'));
        $this->assertStringNotContainsString('Cj0KCQjwGCLIDvalue', (string) $evidence->getContent());

        // The chat shows the message without the code; Owners can show the raw text.
        $conversation = collect($this->getJson('/api/v1/conversations')->json('data'))->first();
        $this->assertSame("Hi, I'd like to know more about your services.", $conversation['last_message_preview']);
        $messages = $this->getJson("/api/v1/conversations/{$conversation['id']}/messages")->assertOk()->json('data');
        $this->assertSame("Hi, I'd like to know more about your services.", $messages[0]['body']);
        $this->assertStringContainsString('XG-7PK9M2Q8TD', (string) $messages[0]['raw_body']);

        // Agent: the chip only; no code, no raw text, no click IDs.
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, function () use ($agent, $conversation) {
            app(NumberAccess::class)->setGrants($agent, [$this->number->id]);
            DB::table('conversations')->where('id', $conversation['id'])->update(['assigned_membership_id' => $agent->id]);
        });
        $this->actingAsMember($agent);
        $agentView = $this->getJson("/api/v1/conversations/{$conversation['id']}/messages")->assertOk();
        $this->assertNull($agentView->json('data.0.raw_body'));
        $this->assertStringNotContainsString('XG-7PK9M2Q8TD', (string) $agentView->getContent());
        $chat = $this->getJson("/api/v1/conversations/{$conversation['id']}")->assertOk();
        $this->assertSame('Reference matched · Google Ads', $chat->json('data.contact.acquisition.label'));
        $this->assertStringNotContainsString('Cj0KCQjwGCLIDvalue', (string) $chat->getContent());

        // Health: last click and last match.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $health = $this->getJson("/api/v1/growth/sites/{$this->site['id']}/health")->json('data');
        $this->assertNotNull($health['last_match_at']);
        $this->assertSame(1, $health['matched_24h']);
    }

    public function test_the_same_code_is_redeemed_once(): void
    {
        $this->beacon()->assertCreated();
        $this->message('Hello (Ref: XG-7PK9M2Q8TD)');
        $this->message('Hello again (Ref: XG-7PK9M2Q8TD)');                // REF-08 same sender reuse
        $this->message('Forwarded (Ref: XG-7PK9M2Q8TD)', '971500000002');  // REF-09 another sender after redemption

        $this->assertSame('google_ads', $this->contact('971500000001')->acquisition_original['source']);
        $this->assertNull($this->contact('971500000002')->acquisition_original, 'another sender is never confirmed');
        $this->tenantContext()->run($this->tenant, function () {
            $this->assertSame(1, DB::table('growth_evidence')->where('confidence', 'confirmed')->count());
            $this->assertSame(1, DB::table('growth_evidence')->where('confidence', 'reused_other_sender')->count());
        });
    }

    public function test_a_message_before_its_click_waits_and_is_matched_when_the_click_arrives(): void
    {
        $this->message('Hi (Ref: XG-7PK9M2Q8TD)');                              // REF-15
        $this->assertNull($this->contact('971500000001')->acquisition_original, 'pending alone is never confirmed');
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame(1, DB::table('growth_pending_references')->where('status', 'pending')->count()));

        $this->beacon()->assertCreated();
        $this->assertSame('google_ads', $this->contact('971500000001')->acquisition_original['source']);
    }

    public function test_an_unregistered_code_becomes_website_details_unavailable_after_the_grace_period(): void
    {
        $this->message('Hi (Ref: XG-NNNNNNNNNN)');
        $this->travel(WebsiteTrackingTestHours::GRACE + 1)->hours();
        Artisan::call('engage:growth:website-maintenance');

        $contact = $this->contact('971500000001');
        $this->assertSame('website_unregistered', $contact->acquisition_original['type']);
        $this->assertSame('Website · details unavailable', $contact->acquisition_original['label']);
        // Not a paid lead (no campaign is known): counted on the website card only.
        $this->assertSame(0, $this->getJson('/api/v1/growth/leads')->json('meta.counts.all'));
    }

    public function test_a_code_outside_its_24_hours_is_not_matched(): void
    {
        $this->beacon()->assertCreated();
        $this->message('Hi (Ref: XG-7PK9M2Q8TD)', at: now()->addDay()->addSecond()->timestamp); // REF-12
        $this->assertNull($this->contact('971500000001')->acquisition_original);
    }

    public function test_a_meta_ad_message_with_a_website_code_is_multiple_sources_with_meta_as_origin(): void
    {
        $this->beacon()->assertCreated();
        $this->message('Hi (Ref: XG-7PK9M2Q8TD)', extra: ['referral' => ['source_id' => '120208000000000001', 'source_type' => 'ad', 'headline' => 'Book now']]);

        $contact = $this->contact('971500000001');
        $this->assertSame('meta_ad', $contact->acquisition_original['source']);
        $this->assertTrue($contact->acquisition_original['conflict']);
        $this->assertSame('Multiple sources · Meta ad', $this->getJson('/api/v1/growth/leads')->json('data.0.contact.acquisition.label'));
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame(2, DB::table('growth_evidence')->where('conflict', true)->count()));
    }

    public function test_instagram_only_with_utm_proof(): void
    {
        $this->beacon(['campaign' => ['utm_source' => 'ig', 'utm_medium' => 'paid', 'utm_campaign' => 'spring']])->assertCreated();
        $this->message('Hi (Ref: XG-7PK9M2Q8TD)');
        $this->assertSame('Reference matched · Meta ad · Instagram', $this->getJson('/api/v1/growth/leads')->json('data.0.contact.acquisition.label'));
        $this->assertSame(1, $this->getJson('/api/v1/growth/leads?source=meta_instagram')->json('meta.counts.all'));
    }

    public function test_organic_website_taps_are_recorded_but_are_not_leads(): void
    {
        $this->beacon(['campaign' => [], 'referrer_host' => 'www.google.com'])->assertCreated();
        $this->message('Hi (Ref: XG-7PK9M2Q8TD)');
        $this->assertSame('google_organic', $this->contact('971500000001')->acquisition_original['source']);
        $this->assertSame(0, $this->getJson('/api/v1/growth/leads')->json('meta.counts.all'));
    }

    public function test_the_live_test_reports_each_stage(): void
    {
        $start = $this->postJson("/api/v1/growth/sites/{$this->site['id']}/test-session")->assertOk()->json('data');
        $this->assertFalse($start['tap_received']);
        $this->get('/v1/config?site_key='.$this->site['site_key'], ['Origin' => self::ORIGIN])->assertOk();
        $this->beacon()->assertCreated();
        $this->message('Hi (Ref: XG-7PK9M2Q8TD)');

        $status = $this->getJson("/api/v1/growth/sites/{$this->site['id']}/test")->json('data');
        $this->assertTrue($status['tracker_seen']);
        $this->assertTrue($status['tap_received']);
        $this->assertTrue($status['message_received']);
        $this->assertTrue($status['matched']);
    }

    public function test_agents_cannot_manage_websites_and_starter_has_none(): void
    {
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/growth/sites')->assertForbidden();
        $this->postJson('/api/v1/growth/sites', ['domain' => 'other.ae', 'connected_phone_ids' => [$this->number->id]])->assertForbidden();

        $this->subscribe($this->tenant, 'starter');
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $this->getJson('/api/v1/growth/sites')->assertNotFound();
        $this->beacon(['reference' => 'XG-EEEEEEEEEE'])->assertForbidden()->assertJsonPath('error', 'site_disabled');
    }

    public function test_chatbots_read_the_message_without_the_code_only_when_the_flag_is_on(): void
    {
        $this->message('menu (Ref: XG-7PK9M2Q8TD)');
        $message = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('direction', 'inbound')->sole());
        $this->assertSame('menu (Ref: XG-7PK9M2Q8TD)', $message->body, 'the stored message never changes');

        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame('menu (Ref: XG-7PK9M2Q8TD)', BotInput::fromMessage($message)->text)); // FLOW-01 flag off

        $this->tenantContext()->bypass(function () {
            $settings = (array) $this->tenant->refresh()->settings;

            $settings['growth']['strip_reference_before_flows'] = true;
            $this->tenant->forceFill(['settings' => $settings])->save();
        });
        GrowthSettings::forget();
        $tenant = $this->tenantContext()->bypass(fn () => $this->tenant->refresh());
        $this->tenantContext()->run($tenant, fn () => $this->assertSame('menu', BotInput::fromMessage($message)->text)); // FLOW-02
    }

    public function test_reference_parsing_and_source_rules(): void
    {
        $this->assertSame('XG-7PK9M2Q8TD', Reference::extract('مرحبًا، أود معرفة المزيد عن خدماتكم. (المرجع: XG-7PK9M2Q8TD)')['token'] ?? null); // REF-02
        $this->assertNull(Reference::extract('XG-7PK9M2Q8TD and XG-7PK9M2Q8TE'));             // REF-06 two codes
        $this->assertNull(Reference::extract('my code XG-7PK9M2Q8TD is here'));                // not at the end
        $this->assertSame('Hi', Reference::clean("Hi  (Ref: XG-7PK9M2Q8TD) \n"));               // REF-05

        $this->assertSame('google_ads', WebsiteSource::classify(['gbraid' => 'x'], null)['source']);
        $this->assertSame('google_ads', WebsiteSource::classify(['utm_source' => 'google', 'utm_medium' => 'cpc'], null)['source']);
        $this->assertSame('google_organic', WebsiteSource::classify([], 'www.google.ae')['source']);
        $this->assertSame('direct', WebsiteSource::classify([], 'www.clinic-example.ae', 'clinic-example.ae')['source']);
        $this->assertSame('Meta ad · Facebook', WebsiteSource::classify(['utm_source' => 'fb'], null)['label']);
        $this->assertSame('paid_other', WebsiteSource::classify(['utm_source' => 'tiktok', 'utm_medium' => 'paid_social'], null)['source']);
        $this->assertNull(WebsiteSource::classify(['gclid' => 'x'], null)['campaign'], 'never a campaign name from gclid');
    }

    public function test_the_greeting_is_editable_and_the_reference_is_always_added(): void
    {
        $this->assertSame("Hi, I'd like to know more about your services.", $this->site['greeting']);
        $this->patchJson("/api/v1/growth/sites/{$this->site['id']}", ['greeting' => 'Hello Glow Clinic, I want to book a visit.', 'greeting_ar' => 'مرحبا، أريد حجز موعد.'])
            ->assertOk()->assertJsonPath('data.greeting', 'Hello Glow Clinic, I want to book a visit.');
        $config = $this->get('/v1/config?site_key='.$this->site['site_key'], ['Origin' => self::ORIGIN])->assertOk();
        $this->assertSame('Hello Glow Clinic, I want to book a visit. (Ref: {reference})', $config->json('templates.en'));
        $this->assertSame('مرحبا، أريد حجز موعد. (المرجع: {reference})', $config->json('templates.ar'));
        $this->assertSame('(Ref: {reference})', $config->json('suffix.en'));

        // Plain text only, no links, no reference of its own, max 200 characters.
        $this->patchJson("/api/v1/growth/sites/{$this->site['id']}", ['greeting' => 'See https://example.com'])->assertStatus(422);
        $this->patchJson("/api/v1/growth/sites/{$this->site['id']}", ['greeting' => 'Hi (Ref: XG-AAAAAAAAAA)'])->assertStatus(422);
        $this->patchJson("/api/v1/growth/sites/{$this->site['id']}", ['greeting' => str_repeat('a', 201)])->assertStatus(422);

        // Matching never depends on the greeting: a custom greeting with its code still matches.
        $this->beacon()->assertCreated();
        $this->message('Hello Glow Clinic, I want to book a visit. (Ref: XG-7PK9M2Q8TD)');
        $this->assertSame('website_reference', $this->contact('971500000001')->acquisition_original['type']);
    }

    public function test_a_visitor_who_deletes_the_reference_is_website_details_unavailable_with_no_guessing(): void
    {
        $this->beacon()->assertCreated(); // a tap did register, but nothing ties the message to it
        $this->message("Hi, I'd like to know more about your services.");
        $contact = $this->contact('971500000001');
        $this->assertSame('website_unregistered', $contact->acquisition_original['type']);
        $this->assertSame('Website · details unavailable', $contact->acquisition_original['label']);
        $this->assertNull($contact->acquisition_original['campaign']);
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame('registered', DB::table('growth_click_events')->value('status'), 'the tap is not consumed by a guess'));

        // An ordinary message isn't mistaken for the website greeting.
        $this->message('Hi', '971500000002');
        $this->assertNull($this->contact('971500000002')->acquisition_original);

        $test = $this->postJson("/api/v1/growth/sites/{$this->site['id']}/test-session")->assertOk();
        $this->travel(1)->minutes();
        $this->message("Hi, I'd like to know more about your services. (Ref: XG-QQQQQQQQQQ)", '971500000003');
        $status = $this->getJson("/api/v1/growth/sites/{$this->site['id']}/test")->json('data');
        $this->assertSame('XG-QQQQQQQQQQ', $status['message']['reference']);
        $this->assertStringContainsString("I'd like to know more", $status['message']['text']);
    }

    public function test_a_returning_contact_keeps_its_original_source_and_campaign_and_is_one_row(): void
    {
        // Created before tracking started, on two numbers.
        $second = $this->tenantContext()->run($this->tenant, function () {
            $waba = WabaAccount::query()->firstOrFail();

            return PhoneNumber::query()->create([
                'waba_account_id' => $waba->id, 'phone_number_id' => '106540352242999', 'display_phone_number' => '+971 58 549 6399',
                'status' => PhoneNumberStatus::Connected, 'onboarding_type' => OnboardingType::NewNumber,
                'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'capabilities' => PhoneNumber::capabilitiesFor(OnboardingType::NewNumber),
            ]);
        });
        $this->patchJson("/api/v1/growth/sites/{$this->site['id']}", ['connected_phone_ids' => [$this->number->id, $second->id]])->assertOk();
        $this->message('Old customer');
        $this->tenantContext()->run($this->tenant, fn () => Contact::query()->update(['created_at' => now()->subMonth()]));
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'Hi on the other number'], 'timestamp' => (string) now()->timestamp], '971500000001', 'AE.971500000001', 'Visitor', '106540352242999')))->assertOk();

        // Two taps from two campaigns: each touch keeps its own campaign and click ID.
        $this->beacon(['campaign' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'autumn', 'gclid' => 'G-ONE']])->assertCreated();
        $this->message('Hi (Ref: XG-7PK9M2Q8TD)');
        $this->travel(18)->minutes();
        $this->beacon(['reference' => 'XG-RRRRRRRRRR', 'campaign' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'winter_sale', 'gclid' => 'G-TWO']])->assertCreated();
        $this->message('Again (Ref: XG-RRRRRRRRRR)');

        $contact = $this->contact('971500000001');
        $evidence = $this->getJson("/api/v1/growth/leads/{$contact->id}/evidence")->assertOk()->json('data');
        $this->assertSame(['autumn', 'winter_sale'], array_map(fn ($e) => $e['details']['campaign']['utm_campaign'], $evidence), 'no touch overwrites another');

        $r = $this->getJson('/api/v1/growth/leads')->assertOk();
        $this->assertCount(1, $r->json('data'), 'one row per person, not per conversation');
        $this->assertSame(['all' => 1, 'new' => 0, 'returning' => 1], $r->json('meta.counts'));
        // Shown with the paid touch that brought the existing contact back, never "Before tracking".
        $this->assertSame('Google Ads', $r->json('data.0.contact.acquisition.source.name'));
        $this->assertSame('winter_sale', $r->json('data.0.contact.acquisition.source.campaign'));
        $this->assertTrue($r->json('data.0.contact.acquisition.returning'));
        $this->assertNull($r->json('data.0.contact.acquisition.source.latest'));
        $this->assertSame(1, count($this->getJson('/api/v1/growth/leads?source=google_ads')->json('data')), 'filtered by the touch it is shown with');
        $this->assertSame(0, count($this->getJson('/api/v1/growth/leads?status=before')->json('data')));
        $kpi = $this->getJson('/api/v1/growth/leads/summary')->json('data');
        $this->assertSame([1, 0, 1], [$kpi['leads'], $kpi['new_customers'], $kpi['returning']]);

        // Marketing: a returning contact never adds a lead to winter_sale.
        Artisan::call('engage:growth:marketing-refresh', ['--days' => 31]);
        $campaigns = $this->getJson('/api/v1/analytics/marketing/campaigns?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString())->assertOk()->json('data.rows');
        $this->assertNotContains('winter_sale', array_column($campaigns, 'label'));
    }
}

/** @internal */
final class WebsiteTrackingTestHours
{
    public const GRACE = 72;
}
