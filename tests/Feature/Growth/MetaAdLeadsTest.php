<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Domain\Access\SystemRole;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Jobs\RecordMessageEvidence;
use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Growth\Services\EvidenceRecorder;
use App\Domain\Growth\Services\MetaReferral;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
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
            $this->assertSame('Meta ad', Contact::query()->sole()->acquisition_original['label']);
        });
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

        $this->app->instance(EvidenceRecorder::class, new class extends EvidenceRecorder
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
            ->assertJsonPath('data.acquisition.status', 'before')
            ->assertJsonPath('data.acquisition.label', 'Before tracking · Latest: Meta ad')
            ->assertJsonPath('data.acquisition.original.label', 'Before tracking');
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
        $this->assertSame(['status' => 'meta', 'label' => 'Meta ad'], $acquisition);
        $this->getJson("/api/v1/growth/leads/{$contactId}/evidence")->assertForbidden();
    }

    public function test_evidence_masks_ids_for_owners_and_admins(): void
    {
        $this->enableGrowth();
        $this->inboundFromAd($this->fixture('full'));
        $contactId = $this->inTenant(fn () => Contact::query()->sole()->id);

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $row = $this->getJson("/api/v1/growth/leads/{$contactId}/evidence")->assertOk()->json('data.0');

        $this->assertSame('Meta ad', $row['label']);
        $this->assertSame('Free consultation this week', $row['details']['headline']);
        $this->assertSame('••••0001', $row['details']['ad_id']);
        $this->assertSame('••••UQ2a', $row['details']['click_id']);
        $this->assertStringNotContainsString('120208000000000001', (string) json_encode($row));
        $this->assertStringNotContainsString('ARAkLkA8rmlF', (string) json_encode($row));
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
