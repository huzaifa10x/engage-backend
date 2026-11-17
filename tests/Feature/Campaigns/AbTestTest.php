<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 5, item 1: A/B testing on campaigns. */
final class AbTestTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['name' => 'Nova Fitness']);
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false, 'campaign_hourly_share' => 100]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['messaging_limit_tier' => 'TIER_10K'])->save());
        $this->actingAsMember($this->owner);
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v27.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'sale_a', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Our big sale starts today.'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Show me']]]]],
                ['id' => '900002', 'name' => 'sale_b', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Half price, today only.'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'I want it']]]]],
                ['id' => '900003', 'name' => 'class_reminder', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Your class is tomorrow.']]],
            ]]),
            'graph.facebook.com/*/messages*' => function (Request $r) {
                $this->sent[] = $r->data();

                return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.AB'.bin2hex(random_bytes(8))]]]);
            },
        ]);
        $this->postJson('/api/v1/templates/sync')->assertOk();
    }

    private function template(string $name): string
    {
        return (string) collect($this->getJson('/api/v1/templates')->json('data'))->firstWhere('name', $name)['id'];
    }

    private function contacts(int $count): void
    {
        $this->tenantContext()->run($this->tenant, function () use ($count) {
            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'wa_id' => '97150'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'name' => "Guest {$i}", 'tags' => '{Sale}', 'consent_state' => 'opted_in', 'marketing_opted_out' => false, 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('contacts')->insert($chunk);
            }
        });
    }

    /** @param array<string, mixed> $ab */
    private function campaign(array $ab, string $template = 'sale_a'): string
    {
        return (string) $this->postJson('/api/v1/campaigns', ['name' => 'Big sale', 'phone_number_id' => $this->number->id, 'template_id' => $this->template($template),
            'audience_tag' => 'Sale', 'ab_test' => ['enabled' => true] + $ab])->assertCreated()->json('data.id');
    }

    /**
     * The customer reacts to the campaign message of $variant: a reply, or a tap on its quick-reply button.
     *
     * @param  list<string>  $contactIds
     */
    private function react(array $contactIds, string $how): void
    {
        $this->tenantContext()->run($this->tenant, function () use ($contactIds, $how) {
            foreach (Message::query()->whereNotNull('campaign_id')->whereIn('contact_id', $contactIds)->get() as $m) {
                $in = $m->replicate(['campaign_id', 'wamid', 'template', 'send_route', 'idempotency_key']);
                $in->forceFill(['direction' => Message::INBOUND, 'origin' => MessageOrigin::Customer, 'status' => 'delivered', 'wamid' => 'wamid.IN'.bin2hex(random_bytes(8)),
                    'type' => $how === 'click' ? 'button' : 'text', 'body' => $how === 'click' ? 'Show me' : 'Interested!',
                    'context_wamid' => $how === 'click' ? $m->wamid : null, 'created_at' => now()->addMinutes(5)])->save();
            }
        });
    }

    /** @return array<string, list<string>> variant → contact ids */
    private function split(string $campaignId): array
    {
        return $this->tenantContext()->run($this->tenant, fn () => DB::table('campaign_recipients')->where('campaign_id', $campaignId)->whereNotNull('variant')
            ->get(['variant', 'contact_id'])->groupBy('variant')->map(fn ($rows) => $rows->pluck('contact_id')->all())->all());
    }

    public function test_1000_contacts_split_500_500_with_no_overlap_results_match_the_message_logs_and_no_winner_below_the_minimum(): void
    {
        $this->contacts(1000);
        $id = $this->campaign(['kind' => 'template', 'goal' => 'reply', 'min_sample' => 600, 'test_percent' => 100, 'template_id' => $this->template('sale_b')]);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $split = $this->split($id);
        $this->assertCount(500, $split['A']);
        $this->assertCount(500, $split['B']);
        $this->assertSame([], array_intersect($split['A'], $split['B']));

        // Every contact received exactly one message, of its own variant's template.
        $this->assertCount(1000, $this->sent);
        $byPhone = collect($this->sent)->groupBy('to');
        $this->assertCount(1000, $byPhone);
        $templates = collect($this->sent)->countBy(fn (array $p) => $p['template']['name']);
        $this->assertSame(500, $templates['sale_a']);
        $this->assertSame(500, $templates['sale_b']);
        $mismatch = $this->tenantContext()->run($this->tenant, fn () => DB::table('campaign_recipients as cr')->join('messages as m', 'm.id', '=', 'cr.message_id')
            ->where('cr.campaign_id', $id)->whereRaw("(cr.variant = 'A') <> (m.template->>'name' = 'sale_a')")->count());
        $this->assertSame(0, $mismatch);

        // 50 replies to A, 100 to B; Meta reports 300 of A delivered.
        $this->react(array_slice($split['A'], 0, 50), 'reply');
        $this->react(array_slice($split['B'], 0, 100), 'reply');
        $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('campaign_id', $id)->whereIn('contact_id', array_slice($split['A'], 0, 300))->update(['status' => 'delivered']));

        $ab = $this->getJson("/api/v1/campaigns/{$id}")->assertOk()->json('data.stats.ab');
        [$a, $b] = $ab['variants'];
        $logs = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('campaign_id', $id)->get());
        $this->assertSame($logs->filter(fn (Message $m) => $m->template['name'] === 'sale_a')->count(), $a['sent']);
        $this->assertSame($logs->filter(fn (Message $m) => $m->template['name'] === 'sale_b')->count(), $b['sent']);
        $this->assertSame(300, $a['delivered']);
        $this->assertSame([50, 100], [$a['replied'], $b['replied']]);
        $this->assertSame(0.1, $a['rate']);
        $this->assertSame(0.2, $b['rate']);
        $this->assertSame(10.0, (float) $ab['difference']);

        // Both below the minimum of 600: no winner, however big the gap.
        $this->assertSame('not_enough_data', $ab['state']);
        $this->assertNull($ab['winner']);
        $this->assertStringStartsWith('Not enough data yet', $ab['message']);

        // With a minimum of 200, the gap is clear: B wins.
        $this->tenantContext()->run($this->tenant, fn () => Campaign::query()->whereKey($id)->update(['ab_test' => DB::raw("jsonb_set(ab_test, '{min_sample}', '200')")]));
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.ab.state', 'winner')->assertJsonPath('data.stats.ab.winner', 'B');
    }

    public function test_a_small_gap_is_not_a_winner_even_above_the_minimum(): void
    {
        $this->contacts(400);
        $id = $this->campaign(['kind' => 'button', 'goal' => 'click', 'min_sample' => 200, 'template_id' => $this->template('sale_b')]);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        $split = $this->split($id);
        $this->react(array_slice($split['A'], 0, 20), 'click');
        $this->react(array_slice($split['B'], 0, 24), 'click');
        $this->react(array_slice($split['B'], 50, 30), 'reply'); // a reply is not a click

        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.ab.variants.0.clicked', 20)->assertJsonPath('data.stats.ab.variants.1.clicked', 24)
            ->assertJsonPath('data.stats.ab.state', 'not_enough_data')->assertJsonPath('data.stats.ab.winner', null);
    }

    public function test_20_percent_test_holds_the_rest_and_sends_the_winner_automatically(): void
    {
        $this->contacts(100);
        $id = $this->campaign(['kind' => 'template', 'goal' => 'click', 'min_sample' => 10, 'test_percent' => 20, 'auto_send_winner' => true, 'template_id' => $this->template('sale_b')]);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'completed')->assertJsonPath('data.stats.sent', 20)->assertJsonPath('data.stats.held', 80)
            ->assertJsonPath('data.stats.ab.variants.0.sent', 10)->assertJsonPath('data.stats.ab.variants.1.sent', 10);
        $this->assertCount(20, $this->sent);

        // No clear winner yet: the scheduler leaves the rest waiting.
        Artisan::call('engage:campaigns:dispatch');
        $this->assertCount(20, $this->sent);

        $split = $this->split($id);
        $this->react(array_slice($split['B'], 0, 8), 'click');
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.ab.winner', 'B');

        Artisan::call('engage:campaigns:dispatch');
        $this->assertCount(100, $this->sent);
        $this->assertSame(90, collect($this->sent)->filter(fn (array $p) => $p['template']['name'] === 'sale_b')->count());
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'completed')->assertJsonPath('data.stats.held', 0)
            ->assertJsonPath('data.ab_test.rest.variant', 'B')->assertJsonPath('data.ab_test.rest.auto', true)
            // The verdict is from the test recipients only.
            ->assertJsonPath('data.stats.ab.variants.1.sent', 10);
        $this->postJson("/api/v1/campaigns/{$id}/send-rest", ['variant' => 'A'])->assertStatus(409);
    }

    public function test_the_owner_can_send_a_variant_to_the_rest_without_a_clear_winner_and_it_is_logged(): void
    {
        $this->contacts(50);
        $id = $this->campaign(['kind' => 'template', 'goal' => 'reply', 'test_percent' => 20, 'template_id' => $this->template('sale_b')]);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 10)->assertJsonPath('data.stats.held', 40);

        $this->postJson("/api/v1/campaigns/{$id}/send-rest", ['variant' => 'A'])->assertOk();
        $this->assertCount(50, $this->sent);
        $log = $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'campaign.ab_rest_sent')->firstOrFail());
        $this->assertSame('Chosen by the owner without a clear winner', $log->getAttribute('meta')['reason']);
    }

    public function test_send_time_test_sends_the_same_template_to_b_later(): void
    {
        $this->contacts(20);
        $id = $this->campaign(['kind' => 'send_time', 'goal' => 'reply', 'b_delay_hours' => 3]);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        $this->assertCount(10, $this->sent);
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'sending')->assertJsonPath('data.wait_reason', 'send_time');

        $this->travel(2)->hours();
        Artisan::call('engage:campaigns:dispatch');
        $this->assertCount(10, $this->sent);

        $this->travel(61)->minutes();
        Artisan::call('engage:campaigns:dispatch');
        $this->assertCount(20, $this->sent);
        $this->assertSame(['sale_a'], collect($this->sent)->pluck('template.name')->unique()->values()->all());
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.stats.ab.variants.0.sent', 10)->assertJsonPath('data.stats.ab.variants.1.sent', 10);
    }

    public function test_variant_b_needs_its_own_approved_template_of_the_same_category(): void
    {
        $base = ['name' => 'Big sale', 'phone_number_id' => $this->number->id, 'template_id' => $this->template('sale_a'), 'audience_tag' => 'Sale'];
        $this->postJson('/api/v1/campaigns', $base + ['ab_test' => ['enabled' => true, 'kind' => 'template', 'goal' => 'reply', 'template_id' => $this->template('class_reminder')]])
            ->assertUnprocessable()->assertJsonFragment(['ab_test.template_id' => ['Both variants need templates of the same category, so they go to the same audience.']]);
        $this->postJson('/api/v1/campaigns', $base + ['ab_test' => ['enabled' => true, 'kind' => 'template', 'goal' => 'reply', 'template_id' => $this->template('sale_a')]])
            ->assertUnprocessable()->assertJsonFragment(['ab_test.template_id' => ['Choose a different template for variant B.']]);
        $this->postJson('/api/v1/campaigns', $base + ['ab_test' => ['enabled' => true, 'kind' => 'send_time', 'goal' => 'reply', 'b_delay_hours' => 0]])
            ->assertUnprocessable();
        $this->postJson('/api/v1/campaigns', $base + ['ab_test' => ['enabled' => true, 'kind' => 'template', 'goal' => 'reply', 'test_percent' => 50, 'template_id' => $this->template('sale_b')]])
            ->assertUnprocessable();
    }
}
