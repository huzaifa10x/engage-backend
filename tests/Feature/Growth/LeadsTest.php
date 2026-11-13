<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Growth Hub › Leads (Developer Handover §I.4, Technical Specification v1.2 §6): CRM-backed list,
 * KPI row, filters, Unmatched clicks, export, and where the stage may be changed.
 */
final class LeadsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'growth');
        $this->connectNumber($this->tenant);
        $this->tenantContext()->bypass(function () {
            $settings = (array) ($this->tenant->settings ?? []);
            $settings['growth'] = ['enabled' => true, 'started_at' => now()->subMinute()->toIso8601String()];
            $this->tenant->forceFill(['settings' => $settings])->save();
        });
        GrowthSettings::forget();

        // One lead from a Meta ad, one with no source.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.L1', 'referral' => [
            'source_id' => '120208000000000001', 'source_type' => 'ad', 'headline' => 'Free consultation this week',
            'ctwa_clid' => 'ARAsecretclickid0001', 'media_type' => 'image',
        ]], '971500000001', 'AE.1', 'Khalid')))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.L2'], '971500000002', 'AE.2', 'Mariam')))->assertOk();
    }

    private function conversationOf(string $waId): Conversation
    {
        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()
            ->whereHas('contact', fn ($c) => $c->where('wa_id', $waId))->sole());
    }

    private function assign(string $waId, TenantMembership $member): void
    {
        $this->tenantContext()->run($this->tenant, fn () => $this->conversationOf($waId)->forceFill(['assigned_membership_id' => $member->id])->save());
    }

    public function test_leads_are_paid_ad_leads_only_and_the_kpis_match_the_list(): void
    {
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));

        // Khalid came from an ad; Mariam just wrote in: she is a contact and a chat, not a lead.
        $r = $this->getJson('/api/v1/growth/leads')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame('Khalid', $r->json('data.0.contact.display_name'));
        $this->assertSame(['all' => 1, 'new' => 1, 'returning' => 0], $r->json('meta.counts'));
        $this->assertSame('Meta ad', $r->json('data.0.contact.acquisition.source.name'));
        $this->assertSame('Ad: "Free consultation this week"', $r->json('data.0.contact.acquisition.source.campaign'));
        $this->assertStringNotContainsString('ARAsecretclickid0001', (string) $r->getContent());
        $this->assertCount(0, $this->getJson('/api/v1/growth/leads?source=meta_instagram')->assertOk()->json('data'), 'Instagram only with proof');

        $kpi = $this->getJson('/api/v1/growth/leads/summary')->assertOk()->json('data');
        $this->assertSame(1, $kpi['leads'], 'the same number as the All leads tab');
        $this->assertSame(1, $kpi['new_customers']);
        $this->assertSame(0, $kpi['returning']);
        // No website tracked yet: the website cards (details lost, unmatched clicks) are hidden.
        $this->assertSame(0, $kpi['websites']);
        $this->assertNull($kpi['unmatched_clicks']);
        $this->assertNull($kpi['website_lost']);
        $this->getJson('/api/v1/growth/leads/unmatched-clicks')->assertOk()->assertJsonCount(0, 'data');

        // Ordinary contacts carry no lead source anywhere (no "Unattributed" / "Before tracking" chip).
        $mariam = collect($this->getJson('/api/v1/contacts')->assertOk()->json('data'))->firstWhere('display_name', 'Mariam');
        $this->assertNull($mariam['acquisition']);
        $chat = collect($this->getJson('/api/v1/conversations')->assertOk()->json('data'))->first(fn ($c) => $c['contact']['display_name'] === 'Mariam');
        $this->assertNull($chat['contact']['acquisition']);
    }

    public function test_a_custom_date_range_lists_the_leads_whose_ad_touch_fell_on_those_days(): void
    {
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['timezone' => 'Asia/Dubai'])->save());
        $today = now('Asia/Dubai')->toDateString();
        $yesterday = now('Asia/Dubai')->subDay()->toDateString();

        $this->assertCount(1, $this->getJson("/api/v1/growth/leads?since={$today}&until={$today}")->assertOk()->json('data'));
        $this->assertSame(1, $this->getJson("/api/v1/growth/leads?since={$yesterday}&until={$today}")->json('meta.counts.all'));
        $this->assertCount(0, $this->getJson("/api/v1/growth/leads?since=2026-01-01&until={$yesterday}")->assertOk()->json('data'));

        // Older than every preset period: only a custom range reaches it.
        $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', '971500000001')->update([
            'acquisition_original' => DB::raw("jsonb_set(acquisition_original, '{occurred_at}', '\"2025-02-10T09:00:00+00:00\"')"),
            'acquisition_latest' => DB::raw("jsonb_set(coalesce(acquisition_latest, acquisition_original), '{occurred_at}', '\"2025-02-10T09:00:00+00:00\"')"),
        ]));
        $this->assertCount(0, $this->getJson('/api/v1/growth/leads?days=365')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/growth/leads?since=2025-02-10&until=2025-02-10')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/growth/leads?since=2025-02-11&until=2025-03-01')->json('data'));

        $this->getJson('/api/v1/growth/leads?since=2025-03-01&until=2025-02-01')->assertStatus(422);
        $this->getJson('/api/v1/growth/leads?since=2025-03-01')->assertStatus(422);
        $this->get('/api/v1/growth/leads/export?since=2025-02-10&until=2025-02-10')->assertOk();
    }

    public function test_a_customer_who_comes_back_through_an_ad_is_a_returning_lead(): void
    {
        // Mariam (no ad at first) later taps an ad.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.L3', 'timestamp' => (string) now()->addMinute()->timestamp, 'referral' => ['source_id' => '120208000000000009', 'source_type' => 'ad']], '971500000002', 'AE.2', 'Mariam')))->assertOk();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));

        $this->assertCount(2, $this->getJson('/api/v1/growth/leads')->assertOk()->json('data'));
        $kpi = $this->getJson('/api/v1/growth/leads/summary')->json('data');
        $this->assertSame([2, 1, 1], [$kpi['leads'], $kpi['new_customers'], $kpi['returning']]);
        $mariam = collect($this->getJson('/api/v1/contacts')->json('data'))->firstWhere('display_name', 'Mariam');
        // Shown with the ad that brought her back, flagged returning (never "Unattributed").
        $this->assertSame('Meta ad', $mariam['acquisition']['label']);
        $this->assertTrue($mariam['acquisition']['returning']);
    }

    public function test_agents_see_only_their_own_leads_and_no_clicks_or_export(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->assign('971500000001', $agent);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($agent, [PhoneNumber::query()->sole()->id]));
        $this->actingAsMember($agent);

        $rows = $this->getJson('/api/v1/growth/leads')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Khalid', $rows[0]['contact']['display_name']);
        $this->assertArrayNotHasKey('original', $rows[0]['contact']['acquisition'], 'agents get the source line, not the evidence');

        $this->assertNull($this->getJson('/api/v1/growth/leads/summary')->assertOk()->json('data.unmatched_clicks'));
        $this->getJson('/api/v1/growth/leads/unmatched-clicks')->assertForbidden();
        $this->get('/api/v1/growth/leads/export')->assertForbidden();
    }

    public function test_export_has_the_source_and_never_click_ids(): void
    {
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));

        $csv = $this->get('/api/v1/growth/leads/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Name,Phone,Source,Campaign,"Match status",Number,"First contact",Agent,Stage,"Last activity"', $csv);
        $this->assertStringContainsString('Khalid,+971500000001,"Meta ad","Ad: ""Free consultation this week""","Meta ad"', $csv);
        $this->assertStringNotContainsString('ARAsecretclickid0001', $csv);
        $this->assertStringNotContainsString('120208000000000001', $csv);
    }

    public function test_starter_has_no_leads_page(): void
    {
        $this->subscribe($this->tenant, 'starter');
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));

        $this->getJson('/api/v1/growth/leads')->assertNotFound();
        $this->getJson('/api/v1/growth/leads/summary')->assertNotFound();
    }

    public function test_the_stage_changes_from_chat_and_leads_and_only_owners_and_admins_change_it_on_the_contact_record(): void
    {
        $stage = $this->tenantContext()->run($this->tenant, fn () => CrmStage::query()->create(['name' => 'Qualified', 'color' => 'good', 'position' => 0]));
        $contactId = $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', '971500000001')->value('id'));

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->postJson("/api/v1/contacts/{$contactId}/stage", ['stage_id' => $stage->id, 'via' => 'contact'])->assertForbidden();
        $this->postJson("/api/v1/contacts/{$contactId}/stage", ['stage_id' => $stage->id, 'via' => 'chat'])->assertOk();
        $this->postJson("/api/v1/contacts/{$contactId}/stage", ['stage_id' => null, 'via' => 'leads'])->assertOk();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $this->postJson("/api/v1/contacts/{$contactId}/stage", ['stage_id' => $stage->id, 'via' => 'contact'])->assertOk()->assertJsonPath('data.stage.name', 'Qualified');
    }
}
