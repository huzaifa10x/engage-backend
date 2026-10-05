<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class CampaignSafeguardsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    private string $template = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->settings(['quiet_hours_enabled' => false]);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);

        Http::fake([
            'graph.facebook.com/v25.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'summer_offer', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy {{2}} off this week.'], ['type' => 'FOOTER', 'text' => 'Reply STOP to opt out']]],
            ]]),
            'graph.facebook.com/v25.0/106540352242922/messages*' => fn () => Http::response([
                'messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.S'.bin2hex(random_bytes(6))]],
            ]),
        ]);
        $this->postJson('/api/v1/templates/sync')->assertOk();
        $this->template = (string) $this->getJson('/api/v1/templates')->json('data.0.id');

        foreach ([['+971501110001', 'Sara Ahmed', ['VIP']], ['+971501110002', 'Omar', ['VIP']], ['+971501110003', '', []]] as [$phone, $name, $tags]) {
            $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => $name ?: null, 'tags' => $tags, 'opted_in' => true])->assertCreated();
        }
    }

    /** @param array<string, mixed> $compliance */
    private function settings(array $compliance): void
    {
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => $compliance]])->save());
    }

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): string
    {
        return (string) $this->postJson('/api/v1/campaigns', $overrides + [
            'name' => 'Summer offer', 'phone_number_id' => $this->number->id, 'template_id' => $this->template,
            'variables' => ['body' => ['{{first_name|there}}', '20%']],
        ])->assertCreated()->json('data.id');
    }

    private function sent(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages'))->count();
    }

    public function test_the_frequency_cap_protects_contacts_from_too_many_marketing_messages(): void
    {
        $this->settings(['quiet_hours_enabled' => false, 'marketing_frequency_cap' => 1]);

        $this->postJson('/api/v1/campaigns/'.$this->draft(['audience_tag' => 'VIP']).'/launch')->assertOk(); // Sara + Omar get 1 each
        $this->assertSame(2, $this->sent());

        // Second marketing campaign in the same week, to everyone: only the contact not yet messaged is eligible.
        $this->getJson("/api/v1/campaigns/audience?template_id={$this->template}")
            ->assertJsonPath('data.matched', 3)->assertJsonPath('data.eligible', 1)->assertJsonPath('data.frequency_cap', 1);

        $second = $this->draft(['name' => 'Second']);
        $this->postJson("/api/v1/campaigns/{$second}/launch")->assertOk();
        $this->assertSame(3, $this->sent());

        $this->getJson("/api/v1/campaigns/{$second}")->assertJsonPath('data.stats.sent', 1)->assertJsonPath('data.stats.skipped', 2)
            ->assertJsonPath('data.failure_reasons.0.reason', 'Frequency cap reached')->assertJsonPath('data.failure_reasons.0.count', 2);
    }

    public function test_quiet_hours_hold_a_marketing_campaign_until_morning(): void
    {
        $this->settings(['quiet_hours_enabled' => true, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '08:00']);
        $this->travelTo(now('Asia/Dubai')->setTime(23, 30)); // late evening in the workspace's time zone

        $this->getJson("/api/v1/campaigns/audience?template_id={$this->template}")->assertJsonPath('data.quiet_until', fn (?string $until) => $until !== null);

        $id = $this->draft();
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'sending')->assertJsonPath('data.stats.sent', 0)
            ->assertJsonPath('data.next_batch_at', fn (?string $at) => $at !== null);
        $this->assertSame(0, $this->sent());

        Artisan::call('engage:campaigns:dispatch'); // still night
        $this->assertSame(0, $this->sent());

        $this->travelTo(now('Asia/Dubai')->addDay()->setTime(8, 1));
        Artisan::call('engage:campaigns:dispatch');
        $this->actingAsMember($this->owner);

        $this->assertSame(3, $this->sent());
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'completed')->assertJsonPath('data.next_batch_at', null);
    }

    public function test_drip_sending_releases_one_batch_per_hour(): void
    {
        $id = $this->draft(['batch_per_hour' => 10]); // minimum allowed
        $this->tenantContext()->run($this->tenant, fn () => Campaign::query()->whereKey($id)->update(['batch_per_hour' => 2]));

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        $this->assertSame(2, $this->sent());
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'sending')->assertJsonPath('data.stats.pending', 1);

        Artisan::call('engage:campaigns:dispatch'); // not an hour yet
        $this->assertSame(2, $this->sent());

        $this->travel(61)->minutes();
        Artisan::call('engage:campaigns:dispatch');
        $this->actingAsMember($this->owner);
        $this->assertSame(3, $this->sent());
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'completed');
    }

    public function test_a_red_quality_rating_pauses_the_campaign_and_blocks_resuming(): void
    {
        $id = $this->draft(['batch_per_hour' => 10]);
        $this->tenantContext()->run($this->tenant, fn () => Campaign::query()->whereKey($id)->update(['batch_per_hour' => 1]));
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        $this->assertSame(1, $this->sent());

        // Quality drops while two recipients are still waiting.
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['quality_rating' => 'RED'])->save());
        $this->travel(61)->minutes();
        Artisan::call('engage:campaigns:dispatch');
        $this->actingAsMember($this->owner);

        $this->assertSame(1, $this->sent());
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.pause_reason', "Paused automatically: the number's quality rating dropped to Red");
        $this->postJson("/api/v1/campaigns/{$id}/resume")->assertStatus(409);

        // Rating recovers → resume sends the rest.
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['quality_rating' => 'GREEN'])->save());
        $this->postJson("/api/v1/campaigns/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'sending');
        $this->assertSame(2, $this->sent());

        // Manual pause, then stop for good: the last recipient is never messaged.
        $this->postJson("/api/v1/campaigns/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/campaigns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->travel(61)->minutes();
        Artisan::call('engage:campaigns:dispatch');
        $this->assertSame(2, $this->sent());
    }

    public function test_meta_flagging_the_number_pauses_running_campaigns(): void
    {
        $id = $this->draft(['batch_per_hour' => 10]);
        $this->tenantContext()->run($this->tenant, fn () => Campaign::query()->whereKey($id)->update(['batch_per_hour' => 1]));
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        Http::fake(['graph.facebook.com/v25.0/106540352242922*' => Http::response(['id' => '106540352242922', 'quality_rating' => 'YELLOW'])]);
        $this->postWebhook($this->webhookBody('phone_number_quality_update', ['display_phone_number' => preg_replace('/\D+/', '', (string) $this->number->display_phone_number), 'event' => 'FLAGGED', 'current_limit' => 'TIER_1K']))->assertOk();

        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.pause_reason', 'Paused automatically: Meta flagged this number for low quality');
    }

    public function test_preview_shows_real_recipients_and_flags_who_would_be_skipped(): void
    {
        $preview = $this->postJson('/api/v1/campaigns/preview', ['template_id' => $this->template, 'variables' => ['body' => ['{{first_name}}', '20%']]])
            ->assertOk()->assertJsonCount(3, 'data');

        $rows = collect($preview->json('data'))->keyBy('contact.phone');
        $this->assertSame('Hi Sara, enjoy 20% off this week.', $rows['+971501110001']['body']);
        $this->assertSame('Reply STOP to opt out', $rows['+971501110001']['footer']);
        $this->assertFalse($rows['+971501110001']['skipped']);
        $this->assertTrue($rows['+971501110003']['skipped']); // no name and no fallback → skipped, never "Hi {{1}}"
    }

    public function test_audience_check_reports_the_numbers_limits_and_campaigns_can_be_duplicated_and_exported(): void
    {
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['messaging_limit_tier' => 'TIER_250', 'quality_rating' => 'GREEN'])->save());

        $this->getJson("/api/v1/campaigns/audience?template_id={$this->template}&phone_number_id={$this->number->id}&audience_tag=VIP")
            ->assertOk()->assertJsonPath('data.matched', 2)->assertJsonPath('data.eligible', 2)
            ->assertJsonPath('data.messaging_limit', 250)->assertJsonPath('data.quality_rating', 'GREEN')->assertJsonPath('data.category', 'MARKETING');

        $id = $this->draft(['audience_tag' => 'VIP', 'notes' => 'Approved by Sara', 'objective' => 'promo']);
        $copy = $this->postJson("/api/v1/campaigns/{$id}/duplicate")->assertCreated()
            ->assertJsonPath('data.name', 'Summer offer (copy)')->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.audience_tag', 'VIP')->assertJsonPath('data.objective', 'promo')->assertJsonPath('data.notes', 'Approved by Sara')->json('data.id');
        $this->assertNotSame($id, $copy);

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        // One recipient replies → counted as a reply.
        $value = $this->inboundValue();
        $value['messages'][0] = ['from' => '971501110001', 'id' => 'wamid.REPLY1', 'timestamp' => (string) (time() + 5), 'type' => 'text', 'text' => ['body' => 'Interested!']];
        $value['contacts'][0]['wa_id'] = '971501110001';
        $this->travel(1)->minutes();
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();

        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 2)->assertJsonPath('data.stats.replied', 1);

        $csv = $this->get("/api/v1/campaigns/{$id}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('phone,name,status,reason,error_code,sent_at', $csv);
        $this->assertStringContainsString('+971501110001,"Sara Ahmed",sent', $csv);
    }
}
