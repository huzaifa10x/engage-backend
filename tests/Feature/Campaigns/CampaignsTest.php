<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Domain\Access\SystemRole;
use App\Domain\Campaigns\Models\Campaign;
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

final class CampaignsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var array<string, string> template name → id */
    private array $templates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);

        Http::fake([
            'graph.facebook.com/v25.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'summer_offer', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy {{2}} off this week.']]],
                ['id' => '900002', 'name' => 'class_reminder', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Reminder: your class is tomorrow.']]],
                ['id' => '900003', 'name' => 'in_review', 'language' => 'en', 'status' => 'PENDING', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Soon']]],
            ]]),
            'graph.facebook.com/v25.0/106540352242922/messages*' => fn () => Http::response([
                'messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.C'.bin2hex(random_bytes(6))]],
            ]),
        ]);
        $this->postJson('/api/v1/templates/sync')->assertOk();
        foreach ($this->getJson('/api/v1/templates')->json('data') as $template) {
            $this->templates[$template['name']] = $template['id'];
        }

        // Audience: 2 opted-in VIPs, 1 VIP without consent, 1 opted-out VIP, 1 opted-in non-VIP.
        $this->contact('+971501110001', 'Sara Ahmed', ['VIP'], optedIn: true);
        $this->contact('+971501110002', 'Omar', ['VIP'], optedIn: true);
        $this->contact('+971501110003', 'Lina', ['VIP']);
        $out = $this->contact('+971501110004', 'Noor', ['VIP'], optedIn: true);
        $this->postJson("/api/v1/contacts/{$out}/consent", ['state' => 'opted_out'])->assertOk();
        $this->contact('+971501110005', 'Zaid', [], optedIn: true);
    }

    /** @param list<string> $tags */
    private function contact(string $phone, string $name, array $tags = [], bool $optedIn = false): string
    {
        return (string) $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => $name, 'tags' => $tags, 'opted_in' => $optedIn])->assertCreated()->json('data.id');
    }

    private function vipSegment(): string
    {
        return (string) $this->postJson('/api/v1/segments', ['name' => 'VIPs', 'match' => 'all', 'rules' => [['field' => 'tags', 'op' => 'has', 'value' => 'VIP']]])
            ->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): string
    {
        return (string) $this->postJson('/api/v1/campaigns', $overrides + [
            'name' => 'Summer offer', 'phone_number_id' => $this->number->id, 'template_id' => $this->templates['summer_offer'],
            'variables' => ['body' => ['{{first_name|there}}', '20%']],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    public function test_a_marketing_campaign_reaches_only_matching_contacts_with_consent(): void
    {
        $segment = $this->vipSegment();

        $this->getJson("/api/v1/campaigns/audience?segment_id={$segment}&template_id={$this->templates['summer_offer']}")
            ->assertOk()->assertJsonPath('data.matched', 4)->assertJsonPath('data.eligible', 2)->assertJsonPath('data.reach_limit', 100000);

        $id = $this->draft(['segment_id' => $segment]);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->getJson("/api/v1/campaigns/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.audience_name', 'VIPs')
            ->assertJsonPath('data.stats.matched', 4)->assertJsonPath('data.stats.eligible', 2)
            ->assertJsonPath('data.stats.sent', 2)->assertJsonPath('data.stats.skipped', 2)->assertJsonPath('data.stats.failed', 0);

        // Personalised per contact, sent as a real template through the Cloud API.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['to'] === '971501110001'
            && $r['template']['name'] === 'summer_offer' && array_column($r['template']['components'][0]['parameters'], 'text') === ['Sara', '20%']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['to'] === '971501110002');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages') && in_array($r['to'], ['971501110003', '971501110004', '971501110005'], true));

        $recipients = collect($this->getJson("/api/v1/campaigns/{$id}/recipients")->assertOk()->json('data'))->keyBy('contact.phone');
        $this->assertSame('accepted', $recipients['+971501110001']['status']);
        $this->assertSame('No marketing opt-in', $recipients['+971501110003']['reason']);
        $this->assertSame('Opted out', $recipients['+971501110004']['reason']);

        $messages = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('campaign_id', $id)->get());
        $this->assertCount(2, $messages);
        $this->assertSame('campaign', $messages[0]->origin->value);
        $this->assertSame('Hi Sara, enjoy 20% off this week.', $messages->firstWhere('body', 'Hi Sara, enjoy 20% off this week.')?->body);

        // Delivery numbers follow Meta's status webhooks.
        $wamid = $messages[0]->wamid;
        $this->postWebhook($this->webhookBody('messages', ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '971500000000', 'phone_number_id' => '106540352242922'],
            'statuses' => [['id' => $wamid, 'status' => 'read', 'timestamp' => (string) time(), 'recipient_id' => '971501110001']]]))->assertOk();
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.read', 1)->assertJsonPath('data.stats.delivered', 1)->assertJsonPath('data.stats.sent', 2);

        // A finished campaign cannot be edited or launched twice.
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertStatus(409);
    }

    public function test_a_utility_campaign_goes_to_everyone_who_has_not_opted_out(): void
    {
        $id = $this->draft(['name' => 'Reminder', 'template_id' => $this->templates['class_reminder'], 'variables' => []]);

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->getJson("/api/v1/campaigns/{$id}")
            ->assertJsonPath('data.stats.matched', 5)->assertJsonPath('data.stats.eligible', 4)->assertJsonPath('data.stats.sent', 4)->assertJsonPath('data.stats.skipped', 1);
    }

    public function test_drafts_are_validated_against_the_template(): void
    {
        $base = ['name' => 'X', 'phone_number_id' => $this->number->id];

        $this->postJson('/api/v1/campaigns', $base + ['template_id' => $this->templates['in_review']])->assertStatus(422);
        $this->postJson('/api/v1/campaigns', $base + ['template_id' => $this->templates['summer_offer'], 'variables' => ['body' => ['only one']]])->assertStatus(422);

        // Nobody eligible → refused at launch with the reason, nothing is sent.
        $empty = $this->postJson('/api/v1/segments', ['name' => 'Nobody', 'match' => 'all', 'rules' => [['field' => 'tags', 'op' => 'has', 'value' => 'Ghost']]])->json('data.id');
        $id = $this->draft(['segment_id' => $empty]);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertStatus(409);
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'draft');

        $this->deleteJson("/api/v1/campaigns/{$id}")->assertNoContent();
    }

    public function test_a_scheduled_campaign_starts_when_due_and_can_be_stopped_before(): void
    {
        $id = $this->draft();
        $this->postJson("/api/v1/campaigns/{$id}/launch", ['scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        Artisan::call('engage:campaigns:dispatch');
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.stats.sent', 0);

        $this->travel(61)->minutes();
        Artisan::call('engage:campaigns:dispatch');
        $this->actingAsMember($this->owner);
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'completed')->assertJsonPath('data.stats.sent', 3);

        // Stopping a scheduled campaign returns it to draft.
        $second = $this->draft(['name' => 'Second']);
        $this->postJson("/api/v1/campaigns/{$second}/launch", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->postJson("/api/v1/campaigns/{$second}/cancel")->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_the_monthly_reach_cap_is_checked_before_the_first_message(): void
    {
        $this->subscribe($this->tenant, 'starter'); // 5,000 campaign messages / month
        $id = $this->draft();

        // 4,998 already used this month → 3 more do not fit.
        $this->tenantContext()->run($this->tenant, function (): void {
            $campaign = Campaign::query()->create(['name' => 'Earlier this month', 'phone_number_id' => $this->number->id, 'template_name' => 'x', 'template_language' => 'en', 'status' => 'completed']);
            $now = now();
            foreach (array_chunk(range(1, 4998), 1000) as $chunk) {
                $contacts = array_map(fn (int $i) => ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'bsuid' => "AE.{$i}", 'source' => 'import', 'created_at' => $now, 'updated_at' => $now], $chunk);
                DB::table('contacts')->insert($contacts);
                DB::table('campaign_recipients')->insert(array_map(fn (array $c) => ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id,
                    'campaign_id' => $campaign->id, 'contact_id' => $c['id'], 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now], $contacts));
            }
        });

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertStatus(402)->assertJsonPath('error.code', 'plan_limit_reached');
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.stats.sent', 0);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages'));
    }

    public function test_campaigns_are_isolated_and_need_permission(): void
    {
        $id = $this->draft();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/campaigns')->assertForbidden();
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertForbidden();

        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/campaigns')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/campaigns/{$id}")->assertNotFound();
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertNotFound();

        // Free plan: broadcasts are not included.
        $free = $this->createTenant();
        $this->actingAsMember($this->addMember($free));
        $this->postJson('/api/v1/campaigns', ['name' => 'X', 'phone_number_id' => $this->number->id, 'template_id' => $this->templates['summer_offer']])
            ->assertStatus(403)->assertJsonPath('error.code', 'feature_not_available');
    }

    public function test_analytics_reports_the_delivery_funnel_per_number_and_template(): void
    {
        $id = $this->draft();
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk(); // 3 marketing sends
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk(); // 1 inbound

        $this->getJson('/api/v1/analytics/overview?days=7')->assertOk()
            ->assertJsonPath('data.totals.outbound', 3)->assertJsonPath('data.totals.sent', 3)->assertJsonPath('data.totals.failed', 0)
            ->assertJsonPath('data.totals.inbound', 1)
            ->assertJsonPath('data.by_origin.campaign', 3)
            ->assertJsonCount(7, 'data.daily')
            ->assertJsonPath('data.daily.6.outbound', 3)->assertJsonPath('data.daily.6.inbound', 1)
            ->assertJsonPath('data.numbers.0.outbound', 3)->assertJsonPath('data.numbers.0.inbound', 1)
            ->assertJsonPath('data.top_templates.0.name', 'summer_offer')->assertJsonPath('data.top_templates.0.total', 3);

        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/analytics/overview')->assertOk()->assertJsonPath('data.totals.outbound', 0)->assertJsonCount(0, 'data.numbers');

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/analytics/overview')->assertForbidden();
    }
}
