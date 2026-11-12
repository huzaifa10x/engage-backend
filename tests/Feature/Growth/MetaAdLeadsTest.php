<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Domain\Access\SystemRole;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Crm\Services\ContactStages;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Jobs\RecordMessageEvidence;
use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Growth\Services\EvidenceRecorder;
use App\Domain\Growth\Services\MetaAds;
use App\Domain\Growth\Services\MetaReferral;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Growth Hub R1 · Meta ad leads (Technical Specification v1.2 CTWA-01…06, Blueprint v1.4).
 * Fixtures: tests/Fixtures/growth/referrals/*.json.
 */
final class MetaAdLeadsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'growth'); // CN-01: Growth, Pro and Enterprise get full Growth Hub
        $this->connectNumber($this->tenant);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/growth/referrals/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private function enableGrowth(?string $startedAt = null): void
    {
        $this->tenantContext()->bypass(function () use ($startedAt) {
            $settings = (array) ($this->tenant->settings ?? []);
            $settings['growth'] = ['enabled' => true, 'started_at' => $startedAt ?? now()->subMinute()->toIso8601String(), 'strip_reference_before_flows' => false];
            $this->tenant->forceFill(['settings' => $settings])->save();
        });
        GrowthSettings::forget();
    }

    /** @param array<string, mixed>|string|null $referral */
    private function inboundFromAd(array|string|null $referral, string $wamid = 'wamid.AD1'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => $wamid, 'referral' => $referral])))->assertOk();
    }

    /** @template T @param callable(): T $fn @return T */
    private function inTenant(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    // CTWA-01 · full referral
    public function test_a_full_referral_becomes_confirmed_meta_evidence_and_the_original_source(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('full'));

        $this->inTenant(function () {
            $evidence = GrowthEvidence::query()->sole();
            $this->assertSame('meta_ctwa', $evidence->evidence_type);
            $this->assertSame('confirmed', $evidence->confidence);
            $this->assertSame('meta_ad', $evidence->source);
            $this->assertSame('120208000000000001', $evidence->provider_fields['referral']['source_id']);
            $this->assertSame('ARAkLkA8rmlFeiCktEJQ-QTwRiyYHAFDLMNDBH0CD3qpjd0HR4irJ6LEkR7JwFF4XvnO2E4Nx0-eM-GABDLOPaOdRMv-_zfUQ2a', $evidence->provider_fields['referral']['ctwa_clid']);
            $this->assertNull($evidence->provider_fields['platform'], 'the referral alone never proves Instagram or Facebook');

            $contact = Contact::query()->sole();
            $this->assertSame('Meta ad', $contact->acquisition_original['label']);
            $this->assertSame('Free consultation this week', $contact->acquisition_original['headline']);
            $this->assertSame($evidence->id, $contact->acquisition_latest['evidence_id']);
            $this->assertNotNull($contact->first_tracked_enquiry_at);

            $this->assertSame(1, Message::query()->count(), 'the chat is recorded as usual');
        });

        $owner = $this->addMember($this->tenant, SystemRole::Owner);
        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);
        $this->actingAsMember($owner)->getJson("/api/v1/contacts/{$contactId}")->assertOk()
            ->assertJsonPath('data.acquisition.status', 'meta')
            ->assertJsonPath('data.acquisition.label', 'Meta ad')
            ->assertJsonPath('data.acquisition.original.headline', 'Free consultation this week')
            ->assertJsonPath('data.acquisition.confidence', 'confirmed');
    }

    // CTWA-02 · no ctwa_clid
    public function test_a_referral_without_click_id_is_still_a_meta_ad_and_stores_only_present_fields(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('no_ctwa_clid'));

        $this->inTenant(function () {
            $fields = GrowthEvidence::query()->sole()->provider_fields['referral'];
            $this->assertArrayNotHasKey('ctwa_clid', $fields);
            $this->assertArrayNotHasKey('media_type', $fields);
            // A boosted post (source_type post) whose source_url is an Instagram link: proof of Instagram.
            $this->assertSame('Meta boosted post · Instagram', Contact::query()->sole()->acquisition_original['label']);
        });

        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $this->assertFalse($this->getJson("/api/v1/growth/leads/{$contactId}/evidence")->assertOk()->json('data.0.details.click_id_sent')); // "Not sent by Meta"
    }

    // Every referral field is kept: the ad creative (media_type, image_url) is shown in Source details.
    public function test_every_referral_field_is_kept_against_the_contact_and_the_conversation(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('full'));

        $this->inTenant(function () {
            $e = GrowthEvidence::query()->sole();
            $this->assertSame('https://scontent.xx.fbcdn.net/v/redacted.jpg', $e->provider_fields['referral']['image_url']);
            $this->assertSame(Conversation::query()->sole()->id, $e->conversation_id);
            $this->assertNotNull($e->phone_number_id);
        });

        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $d = $this->getJson("/api/v1/growth/leads/{$contactId}/evidence")->assertOk()->json('data.0.details');
        $this->assertSame('image', $d['media_type']);
        $this->assertSame('https://scontent.xx.fbcdn.net/v/redacted.jpg', $d['creative_url']);
        // The full ad ID never leaves the server (it groups leads internally).
        $this->assertStringNotContainsString('120208000000000001', (string) $this->getJson("/api/v1/contacts/{$contactId}")->getContent());
    }

    public function test_a_repeated_webhook_keeps_a_referral_the_first_copy_did_not_have(): void
    {
        $this->enableGrowth();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.TWICE'])))->assertOk();
        $this->inboundFromAd($this->fixture('full'), 'wamid.TWICE');
        $this->inboundFromAd($this->fixture('full'), 'wamid.TWICE');

        $this->inTenant(function () {
            $this->assertSame(1, Message::query()->count());
            $this->assertSame(1, GrowthEvidence::query()->count());
            $this->assertSame('Meta ad', Contact::query()->sole()->acquisition_original['label']);
        });
    }

    public function test_an_instagram_ad_link_proves_instagram_and_an_ad_tagged_one_platform_relabels_its_leads(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd(['source_id' => '120208000000000010', 'source_type' => 'ad', 'source_url' => 'https://www.instagram.com/p/Cabc123/'], 'wamid.IG');
        $this->inTenant(fn () => $this->assertSame('Meta ad · Instagram', Contact::query()->sole()->acquisition_original['label']));

        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.FB', 'referral' => ['source_id' => '120208000000000020', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x']], '971500000077', 'AE.77', 'Omar')))->assertOk();
        $this->inTenant(fn () => $this->assertSame('Meta ad', Contact::query()->where('wa_id', '971500000077')->sole()->acquisition_original['label']));

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $ads = collect($this->getJson('/api/v1/growth/meta-ads')->assertOk()->json('data'));
        $this->assertCount(2, $ads);
        $fb = $ads->firstWhere('ad_id', '120208••••••0020');
        $this->assertSame(1, $fb['leads']);

        $this->putJson("/api/v1/growth/meta-ads/{$fb['id']}", ['platform' => 'facebook'])->assertOk()->assertJsonPath('data.leads_updated', 1);
        $this->inTenant(function () {
            $c = Contact::query()->where('wa_id', '971500000077')->sole();
            $this->assertSame('Meta ad · Facebook', $c->acquisition_original['label']);
            $this->assertSame('This ad runs on Facebook only (set in Tracking Setup)', GrowthEvidence::query()->where('wa_message_id', 'wamid.FB')->sole()->provider_fields['platform_proof']);
        });

        // Leads grouped by ad, and the "Meta ad · Facebook" filter now finds it.
        $this->assertCount(1, $this->getJson("/api/v1/growth/leads?ad={$fb['id']}")->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/growth/leads?source=meta_facebook')->assertOk()->json('data'));

        // A new lead from the tagged ad is Facebook from the start; clearing the tag makes it plain "Meta ad".
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.FB2', 'referral' => ['source_id' => '120208000000000020']], '971500000088', 'AE.88', 'Lina')))->assertOk();
        $this->inTenant(fn () => $this->assertSame('Meta ad · Facebook', Contact::query()->where('wa_id', '971500000088')->sole()->acquisition_original['label']));
        $this->putJson("/api/v1/growth/meta-ads/{$fb['id']}", ['platform' => null])->assertOk()->assertJsonPath('data.leads_updated', 2);
        $this->inTenant(fn () => $this->assertSame('Meta ad', Contact::query()->where('wa_id', '971500000088')->sole()->acquisition_original['label']));
    }

    public function test_boosted_posts_are_told_apart_from_paid_ads(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd(['source_id' => '120208000000000030', 'source_type' => 'post', 'source_url' => 'https://fb.me/p'], 'wamid.POST');
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.PAID', 'referral' => ['source_id' => '120208000000000031', 'source_type' => 'ad']], '971500000055', 'AE.55', 'Reem')))->assertOk();

        $this->inTenant(fn () => $this->assertSame('Meta boosted post', Contact::query()->where('wa_id', '971501234567')->sole()->acquisition_original['label']));
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $this->assertSame('Reem', $this->getJson('/api/v1/growth/leads?source=meta_paid')->assertOk()->json('data.0.contact.display_name'));
        $this->assertCount(1, $this->getJson('/api/v1/growth/leads?source=meta_post')->assertOk()->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/growth/leads?source=meta')->assertOk()->json('data'));
        $this->assertSame(1, $this->getJson('/api/v1/growth/leads/summary')->assertOk()->json('data.meta_posts'));
    }

    public function test_each_ad_shows_its_receiving_number_and_how_far_its_leads_got_within_30_days(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd(['source_id' => '120208000000000040', 'source_type' => 'ad'], 'wamid.O1');
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.O2', 'referral' => ['source_id' => '120208000000000040']], '971500000066', 'AE.66', 'Ali')))->assertOk();

        [$qualified, $won] = $this->inTenant(fn () => [
            CrmStage::query()->create(['name' => 'Qualified', 'color' => 'info', 'position' => 1]),
            CrmStage::query()->create(['name' => 'Won', 'color' => 'good', 'position' => 2]),
        ]);
        $this->inTenant(function () use ($qualified, $won) {
            $stages = app(ContactStages::class);
            $first = Contact::query()->where('wa_id', '971501234567')->sole();
            $second = Contact::query()->where('wa_id', '971500000066')->sole();
            $stages->move($first, $qualified->id);
            $stages->move($second, $qualified->id);
            // Won, but after the 30-day window: the second lead still counts as Qualified.
            $this->travel(31)->days();
            $stages->move($second, $won->id);
            $this->travelBack();
            $stages->move($first, $won->id);
        });

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $ad = $this->getJson('/api/v1/growth/meta-ads')->assertOk()->json('data.0');
        $this->assertSame(2, $ad['leads']);
        $this->assertSame('+971 58 549 6310', $ad['numbers'][0]['display_phone_number']);
        $this->assertSame(2, $ad['numbers'][0]['chats']);
        $this->assertSame([['name' => 'Won', 'leads' => 1], ['name' => 'Qualified', 'leads' => 1]], array_map(fn ($o) => ['name' => $o['stage']['name'], 'leads' => $o['leads']], $ad['outcomes']));
    }

    public function test_agents_cannot_tag_ads(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('full'));
        $id = $this->inTenant(fn () => DB::table('growth_meta_ads')->value('id'));

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->putJson("/api/v1/growth/meta-ads/{$id}", ['platform' => 'instagram'])->assertForbidden();
        $this->getJson('/api/v1/growth/meta-ads')->assertForbidden();
    }

    // CTWA-03 · minimal referral
    public function test_a_referral_with_only_source_id_is_enough(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd(['source_id' => '120208000000000003']);

        $this->inTenant(fn () => $this->assertSame(['source_id' => '120208000000000003'], GrowthEvidence::query()->sole()->provider_fields['referral']));
    }

    // CTWA-04 · malformed referral never drops the chat
    public function test_a_malformed_referral_records_the_message_and_no_evidence(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd(['headline' => '   ', 'unexpected' => ['x' => 1]], 'wamid.BAD1');
        $this->inboundFromAd('not-an-object', 'wamid.BAD2');

        $this->inTenant(function () {
            $this->assertSame(2, Message::query()->count());
            $this->assertSame(0, GrowthEvidence::query()->count());
            $this->assertNull(Contact::query()->sole()->acquisition_original);
        });
    }

    // CTWA-05 · duplicate webhook
    public function test_a_repeated_webhook_records_the_evidence_once(): void
    {
        $this->enableGrowth();
        $value = $this->inboundValue(['id' => 'wamid.DUP', 'referral' => $this->fixture('full')]);
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();
        $value['messages'][0]['timestamp'] = (string) (now()->timestamp + 3); // a retry with a different body hash
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();

        // Even a second run of the background job changes nothing.
        $this->inTenant(fn () => app(EvidenceRecorder::class)->recordMetaReferral(Message::query()->sole(), $this->tenant));

        $this->inTenant(fn () => $this->assertSame(1, GrowthEvidence::query()->count()));
    }

    // CTWA-06 · attribution failure never blocks the chat
    public function test_an_attribution_failure_does_not_block_the_message(): void
    {
        $this->enableGrowth();
        Queue::fake([RecordMessageEvidence::class]);

        $this->inboundFromAd($this->fixture('full'));

        // The chat is saved and the evidence work is queued separately (its own job, own retries).
        Queue::assertPushed(RecordMessageEvidence::class, 1);
        $messageId = $this->inTenant(fn () => Message::query()->sole()->id);

        $this->app->instance(EvidenceRecorder::class, new class(app(MetaAds::class)) extends EvidenceRecorder
        {
            public function recordMetaReferral(Message $message, Tenant $tenant): ?GrowthEvidence
            {
                throw new RuntimeException('attribution store unavailable');
            }
        });

        try {
            $this->inTenant(fn () => app()->call([new RecordMessageEvidence($messageId), 'handle']));
            $this->fail('the job should report the failure so the queue retries it');
        } catch (RuntimeException) {
        }

        $this->inTenant(function () {
            $this->assertSame(1, Message::query()->count());
            $this->assertSame(0, GrowthEvidence::query()->count());
            $this->assertSame(1, Conversation::query()->sole()->unread_count);
        });
    }

    public function test_the_raw_message_is_never_changed(): void
    {
        $this->enableGrowth();
        $referral = $this->fixture('full');
        $this->inboundFromAd($referral);

        $this->inTenant(function () use ($referral) {
            $message = Message::query()->sole();
            $this->assertSame('Hi, do you have this in blue?', $message->body);
            $this->assertEquals($referral, $message->content['referral']);
        });
    }

    public function test_an_existing_contact_stays_before_tracking_and_gets_a_latest_touch(): void
    {
        // The contact wrote before Growth Hub was switched on.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.OLD'])))->assertOk();
        $this->travel(5)->minutes();
        $this->enableGrowth(now()->subMinute()->toIso8601String());

        $this->inboundFromAd($this->fixture('full'), 'wamid.AD-LATER');

        $this->inTenant(function () {
            $contact = Contact::query()->sole();
            $this->assertNull($contact->acquisition_original, 'a before-tracking contact never gets an original source');
            $this->assertSame('Meta ad', $contact->acquisition_latest['label']);
        });

        $owner = $this->addMember($this->tenant, SystemRole::Owner);
        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);
        $this->actingAsMember($owner)->getJson("/api/v1/contacts/{$contactId}")->assertOk()
            // Shown with the ad that brought the existing contact back — never "Before tracking".
            ->assertJsonPath('data.acquisition.status', 'meta')
            ->assertJsonPath('data.acquisition.label', 'Meta ad')
            ->assertJsonPath('data.acquisition.returning', true)
            ->assertJsonPath('data.acquisition.returning_label', 'Before tracking · Latest: Meta ad')
            ->assertJsonPath('data.acquisition.source.name', 'Meta ad')
            ->assertJsonPath('data.acquisition.original.label', 'Existing contact (from before tracking started)');
    }

    public function test_a_contact_whose_first_message_had_no_ad_is_unattributed_even_after_a_later_ad(): void
    {
        $this->enableGrowth();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.FIRST'])))->assertOk();
        $this->travel(1)->minutes();
        $this->inboundFromAd($this->fixture('full'), 'wamid.SECOND');

        $this->inTenant(function () {
            $contact = Contact::query()->sole();
            $this->assertNull($contact->acquisition_original, 'never confirmed by timing');
            $this->assertSame('Meta ad', $contact->acquisition_latest['label']);
        });
    }

    public function test_nothing_is_recorded_or_shown_when_growth_hub_is_off(): void
    {
        $this->inboundFromAd($this->fixture('full'));

        $this->inTenant(fn () => $this->assertSame(0, GrowthEvidence::query()->count()));

        $owner = $this->addMember($this->tenant, SystemRole::Owner);
        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);
        $this->actingAsMember($owner);
        $this->getJson("/api/v1/contacts/{$contactId}")->assertOk()->assertJsonPath('data.acquisition', null);
        $this->getJson("/api/v1/growth/leads/{$contactId}/evidence")->assertNotFound();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.growth.enabled', false);
    }

    public function test_agents_see_the_chip_only_and_never_the_evidence(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('full'));
        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);

        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->actingAsMember($agent);
        $acquisition = $this->getJson("/api/v1/contacts/{$contactId}")->assertOk()->json('data.acquisition');
        // Agents get the chip and the Source panel line (name, ad headline): never codes or click IDs.
        $this->assertSame([
            'status' => 'meta', 'label' => 'Meta ad', 'returning' => false,
            'source' => ['name' => 'Meta ad', 'campaign' => 'Ad: "Free consultation this week"', 'landing_page' => null, 'latest' => null],
        ], $acquisition);
        $this->getJson("/api/v1/growth/leads/{$contactId}/evidence")->assertForbidden();
    }

    public function test_evidence_shows_the_ad_id_but_never_the_click_id(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('full'));
        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $row = $this->getJson("/api/v1/growth/leads/{$contactId}/evidence")->assertOk()->json('data.0');

        $this->assertSame('Meta ad', $row['label']);
        $this->assertSame('Free consultation this week', $row['details']['headline']);
        // The business's own ad ID is shown in full (7b4dd88, so it can be matched in Ads Manager);
        // the per-person click ID never leaves the server.
        $this->assertSame('120208000000000001', $row['details']['ad_id']);
        $this->assertTrue($row['details']['click_id_sent']); // "Present", never the value
        $this->assertSame('image', $row['details']['media_type']); // the ad's creative (Source details)
        $this->assertStringNotContainsString('ARAkLkA8rmlF', (string) json_encode($row));
    }

    public function test_starter_shows_the_meta_ad_chip_only(): void
    {
        $this->subscribe($this->tenant, 'starter');
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('full'), 'wamid.STARTER-AD');
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.STARTER-PLAIN'], waId: '971500000999', bsuid: null, name: 'No Ad')))->assertOk();

        $owner = $this->addMember($this->tenant, SystemRole::Owner);
        $this->actingAsMember($owner);
        [$fromAd, $plain] = $this->inTenant(fn () => [
            Contact::query()->where('wa_id', '971501234567')->sole()->id,
            Contact::query()->where('wa_id', '971500000999')->sole()->id,
        ]);

        $this->assertSame(['status' => 'meta', 'label' => 'Meta ad', 'returning' => false], $this->getJson("/api/v1/contacts/{$fromAd}")->assertOk()->json('data.acquisition'));
        $this->getJson("/api/v1/contacts/{$plain}")->assertOk()->assertJsonPath('data.acquisition', null);
        $this->getJson("/api/v1/growth/leads/{$fromAd}/evidence")->assertNotFound();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.growth.tier', 'chip');

        // Evidence is still recorded, so an upgrade shows the history at once.
        $this->subscribe($this->tenant, 'pro');
        $this->getJson("/api/v1/growth/leads/{$fromAd}/evidence")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.growth.tier', 'full');
    }

    public function test_platform_labels_need_proof(): void
    {
        $this->assertSame('Meta ad', MetaReferral::label());
        $this->assertSame('Meta ad · Instagram', MetaReferral::label('instagram'));
        $this->assertSame('Meta ad · Facebook', MetaReferral::label('facebook'));
        $this->assertNull(MetaReferral::parse(['source_type' => '']));
        $this->assertSame(['source_type' => 'ad', 'headline' => 'Hi'], MetaReferral::parse(['source_type' => ' ad ', 'headline' => 'Hi', 'extra' => 'x']));
    }
}
