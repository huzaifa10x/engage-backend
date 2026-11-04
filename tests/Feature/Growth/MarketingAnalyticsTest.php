<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Crm\Services\ContactStages;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Analytics → Marketing (Marketing Analytics Specification §9 tests). */
final class MarketingAnalyticsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    /** @var array<string, CrmStage> */
    private array $stages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'growth');
        $this->connectNumber($this->tenant);
        $this->tenantContext()->bypass(function () {
            $this->tenant->forceFill(['settings' => ['growth' => ['enabled' => true, 'started_at' => now()->subDays(60)->toIso8601String()]]])->save();
        });
        GrowthSettings::forget();
        $this->in(function () {
            foreach (['Contacted' => 1, 'Qualified' => 2, 'Won' => 3] as $name => $pos) {
                $this->stages[$name] = CrmStage::query()->create(['name' => $name, 'color' => 'info', 'position' => $pos]);
            }
        });
    }

    /** @template T @param callable(): T $fn @return T */
    private function in(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    /** @param array<string, mixed>|null $referral */
    private function message(string $waId, string $wamid, ?array $referral = null, ?int $at = null): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(array_filter([
            'id' => $wamid, 'referral' => $referral, 'timestamp' => (string) ($at ?? now()->timestamp),
        ]), $waId, 'AE.'.$waId, 'Lead '.$waId)))->assertOk();
    }

    private function move(string $waId, string $stage): void
    {
        $this->in(fn () => app(ContactStages::class)->move(Contact::query()->where('wa_id', $waId)->sole(), $this->stages[$stage]->id));
    }

    /** @return array<string, string> */
    private function period(): array
    {
        $today = now('Asia/Dubai');

        return ['from' => $today->copy()->subDays(29)->toDateString(), 'to' => $today->toDateString()];
    }

    private function seedLeads(): void
    {
        // E: a lead in the previous period (first message 40 days ago).
        $this->message('971500000005', 'wamid.E');
        $this->in(fn () => DB::table('messages')->where('wamid', 'wamid.E')->update(['meta_timestamp' => now()->subDays(40)]));
        // A: Instagram (the ad link proves it); B: another ad; C: no source, two messages.
        $this->message('971500000001', 'wamid.A', ['source_id' => '120208000000000101', 'source_type' => 'ad', 'headline' => 'Glow in 30 minutes', 'source_url' => 'https://www.instagram.com/p/x/']);
        $this->message('971500000002', 'wamid.B', ['source_id' => '120208000000000102', 'source_type' => 'ad', 'headline' => 'Laser hair removal']);
        $this->message('971500000003', 'wamid.C1');
        $this->message('971500000003', 'wamid.C2');
        // D: existed before tracking; its new ad tap is a returning contact, never a new lead.
        $this->in(fn () => Contact::query()->create(['wa_id' => '971500000004', 'name' => 'Old customer']));
        $this->in(fn () => DB::table('contacts')->where('wa_id', '971500000004')->update(['created_at' => now()->subDays(90)]));
        $this->message('971500000004', 'wamid.D', ['source_id' => '120208000000000101', 'source_type' => 'ad']);

        $this->move('971500000001', 'Qualified');
        $this->move('971500000002', 'Contacted');
        $this->travel(31)->days();
        $this->move('971500000003', 'Won'); // day 31: not counted
        $this->travelBack();
    }

    public function test_reports_count_unique_new_leads_and_need_the_stage_mapping_for_qualified(): void
    {
        $this->seedLeads();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $q = http_build_query($this->period());

        $o = $this->getJson("/api/v1/analytics/marketing/overview?{$q}")->assertOk()->json('data');
        $this->assertSame(3, $o['new_leads']['value'], 'C counted once; D (before tracking) never');
        $this->assertSame(1, $o['new_leads']['previous']);
        $this->assertEquals(200, $o['new_leads']['change_pct']);
        $this->assertFalse($o['stage_mapping_ready']);
        $this->assertNull($o['qualified_leads']['value']);
        $this->assertNull($o['qualification_rate']['value']);
        $this->assertEquals(66.7, $o['confirmed_coverage']['value']);
        $this->assertSame(1, $o['returning_contacts']);

        $this->putJson('/api/v1/analytics/marketing/stage-mapping', ['contacted' => [$this->stages['Contacted']->id], 'qualified' => [$this->stages['Qualified']->id, $this->stages['Won']->id]])
            ->assertOk()->assertJsonPath('data.ready', true);

        $o = $this->getJson("/api/v1/analytics/marketing/overview?{$q}")->json('data');
        $this->assertSame(1, $o['qualified_leads']['value'], 'Won on day 31 does not count');
        $this->assertEquals(33.3, $o['qualification_rate']['value']);
        $this->assertTrue($o['provisional']);
        $old = http_build_query(['from' => now('Asia/Dubai')->subDays(59)->toDateString(), 'to' => now('Asia/Dubai')->subDays(31)->toDateString()]);
        $this->assertFalse($this->getJson("/api/v1/analytics/marketing/overview?{$old}")->json('data.provisional'), 'a period older than 30 days is final');

        $this->assertSame([['step' => 'new', 'count' => 3], ['step' => 'contacted', 'count' => 2], ['step' => 'qualified', 'count' => 1]],
            $this->getJson("/api/v1/analytics/marketing/funnel?{$q}")->json('data.steps'));

        $sources = collect($this->getJson("/api/v1/analytics/marketing/sources?{$q}")->json('data.rows'))->keyBy('bucket');
        $this->assertSame(1, $sources['meta_instagram']['new_leads']);
        $this->assertSame(1, $sources['meta_instagram']['qualified']);
        $this->assertSame(1, $sources['meta_unsplit']['new_leads']);
        $this->assertSame(1, $sources['unattributed']['new_leads']);

        $campaigns = collect($this->getJson("/api/v1/analytics/marketing/campaigns?{$q}")->json('data.rows'));
        $this->assertSame('“Glow in 30 minutes” (Meta ad)', $campaigns->firstWhere('bucket', 'meta_instagram')['label']);
        $this->assertSame('Campaign not available', $campaigns->last()['label']);
        $this->assertStringNotContainsString('120208000000000101', (string) json_encode($campaigns));

        $trend = $this->getJson("/api/v1/analytics/marketing/trend?{$q}")->json('data.points');
        $this->assertCount(30, $trend);
        $this->assertSame(3, array_sum(array_column($trend, 'new_leads')));
    }

    public function test_the_leads_drill_down_lists_exactly_the_number_clicked(): void
    {
        $this->seedLeads();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $this->putJson('/api/v1/analytics/marketing/stage-mapping', ['contacted' => [$this->stages['Contacted']->id], 'qualified' => [$this->stages['Qualified']->id, $this->stages['Won']->id]])->assertOk();
        $p = $this->period();
        $q = http_build_query($p);
        $campaign = collect($this->getJson("/api/v1/analytics/marketing/campaigns?{$q}")->json('data.rows'))->firstWhere('bucket', 'meta_instagram')['campaign_key'];

        $count = fn (array $extra) => count($this->getJson('/api/v1/growth/leads?'.http_build_query($p + $extra))->assertOk()->json('data'));
        $this->assertSame(3, $count([]));
        $this->assertSame(2, $count(['step' => 'contacted']));
        $this->assertSame(1, $count(['step' => 'qualified']));
        $this->assertSame(1, $count(['bucket' => 'unattributed']));
        $this->assertSame(2, $count(['bucket' => 'meta']));
        $this->assertSame(1, $count(['campaign' => $campaign]));
    }

    public function test_agents_have_no_access_and_starter_gets_an_upgrade_prompt(): void
    {
        $q = http_build_query($this->period());
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->in(fn () => app(NumberAccess::class)->setGrants($agent, []));
        $this->actingAsMember($agent);
        $this->getJson("/api/v1/analytics/marketing/overview?{$q}")->assertForbidden();

        $this->subscribe($this->tenant, 'starter');
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $this->getJson("/api/v1/analytics/marketing/overview?{$q}")->assertStatus(402);
    }

    public function test_another_workspace_sees_nothing(): void
    {
        $this->seedLeads();
        $other = $this->createTenant();
        $this->subscribe($other, 'growth');
        $this->tenantContext()->bypass(fn () => $other->forceFill(['settings' => ['growth' => ['enabled' => true, 'started_at' => now()->subDays(60)->toIso8601String()]]])->save());
        GrowthSettings::forget();

        $this->actingAsMember($this->addMember($other, SystemRole::Owner));
        $this->assertSame(0, $this->getJson('/api/v1/analytics/marketing/overview?'.http_build_query($this->period()))->assertOk()->json('data.new_leads.value'));
    }
}
