<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Access\SystemRole;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Conversions to Meta, 3b: the "Meta conversion reporting" card in Integrations (its API). */
final class ConversionsCardTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    /** @var list<string> */
    private array $scopes = ['whatsapp_business_management', 'whatsapp_business_messaging', 'whatsapp_business_manage_events'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        $this->tenantContext()->run($this->tenant, fn () => ConversionsAccess::set($this->tenant, true));
        $this->actingAsMember($this->owner);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_contains($r->url(), 'debug_token') => Http::response(['data' => ['is_valid' => true, 'scopes' => $this->scopes]]),
                str_ends_with($path, '/dataset') => in_array('whatsapp_business_manage_events', $this->scopes, true) ? Http::response(['id' => '1503923451234567'])
                    : Http::response(['error' => ['message' => '(#200) Permission denied', 'type' => 'OAuthException', 'code' => 200]], 403),
                str_ends_with($path, '/events') => Http::response(['events_received' => count($r['data']), 'fbtrace_id' => 'AbC']),
                default => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT'.Str::random(6)]]]),
            };
        }]);
    }

    private function fromAd(string $waId = '971501234567', ?string $clid = 'ARAkLkA8rmlFeiCktEJQ'): string
    {
        $referral = array_filter(['source_id' => '120210000000000001', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x', 'headline' => 'Free trial class', 'ctwa_clid' => $clid]);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['referral' => $referral], $waId, 'AE.'.$waId, 'Sara Ahmed')))->assertOk();

        return (string) $this->tenantContext()->bypass(fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', $waId))->value('id'));
    }

    public function test_a_workspace_without_the_flag_gets_no_card(): void
    {
        $this->tenantContext()->run($this->tenant, fn () => ConversionsAccess::set($this->tenant, false));
        $this->getJson('/api/v1/conversions')->assertNotFound();
        $this->getJson('/api/v1/conversions/events')->assertNotFound();
        $this->postJson('/api/v1/conversions/test-event', ['conversation_id' => (string) Str::uuid()])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_switching_an_account_on_creates_the_dataset_and_without_the_permission_it_says_not_enabled(): void
    {
        $account = $this->getJson('/api/v1/conversions')->assertOk()->assertJsonPath('data.accounts.0.reporting', false)->json('data.accounts.0.id');

        $this->scopes = ['whatsapp_business_management', 'whatsapp_business_messaging'];
        $this->postJson("/api/v1/conversions/accounts/{$account}/reporting", ['enabled' => true])->assertUnprocessable()
            ->assertJsonPath('error.details.fields.enabled.0', fn ($m) => str_starts_with($m, 'Not enabled'));
        $this->getJson('/api/v1/conversions')->assertJsonPath('data.accounts.0.permission', 'not_enabled')->assertJsonPath('data.accounts.0.reporting', false);

        $this->scopes[] = 'whatsapp_business_manage_events';
        $this->postJson("/api/v1/conversions/accounts/{$account}/reporting", ['enabled' => true])->assertOk()->assertJsonPath('data.dataset_id', '1503923451234567');
        $this->getJson('/api/v1/conversions')->assertJsonPath('data.accounts.0.permission', 'enabled')
            ->assertJsonPath('data.accounts.0.reporting', true)->assertJsonPath('data.accounts.0.dataset_id', '1503923451234567');

        $this->postJson("/api/v1/conversions/accounts/{$account}/reporting", ['enabled' => false])->assertOk();
        $this->getJson('/api/v1/conversions')->assertJsonPath('data.accounts.0.reporting', false);
    }

    public function test_rules_pick_tags_and_flow_steps_for_leads_and_turn_purchases_on_or_off(): void
    {
        $flowId = (string) $this->postJson('/api/v1/chatbots', ['name' => 'Booking', 'phone_number_id' => $this->tenantContext()->bypass(fn () => DB::table('phone_numbers')->value('id')), 'template' => 'blank'])->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$flowId}", ['draft' => ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'title' => 'Start', 'x' => 0, 'y' => 0, 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'booked']]],
            ['id' => 'booked', 'type' => 'message', 'title' => 'Booking confirmed', 'x' => 0, 'y' => 0, 'config' => ['text' => 'Booked'], 'outputs' => [['id' => 'next', 'to' => 'team']]],
            ['id' => 'team', 'type' => 'handoff', 'title' => 'Team', 'x' => 0, 'y' => 0, 'config' => ['text' => 'A person will reply.'], 'outputs' => []],
        ]]])->assertOk();
        $this->postJson("/api/v1/chatbots/{$flowId}/publish")->assertOk();

        $this->getJson('/api/v1/conversions')->assertJsonPath('data.flows.0.name', 'Booking')->assertJsonPath('data.flows.0.steps.0.title', 'Booking confirmed');
        $this->putJson('/api/v1/conversions/rules', ['lead_tags' => ['Booked', 'vip'], 'lead_steps' => [['flow_id' => $flowId, 'step_id' => 'booked']], 'purchases' => true])->assertOk()
            ->assertJsonPath('data.rules.lead_tags', ['booked', 'vip'])
            ->assertJsonPath('data.rules.lead_steps.0.label', 'Booking › Booking confirmed')
            ->assertJsonPath('data.rules.purchases', true);

        // A tag taken off the list stops counting; purchases switched off.
        $this->putJson('/api/v1/conversions/rules', ['lead_tags' => ['booked'], 'lead_steps' => [], 'purchases' => false])->assertOk()
            ->assertJsonPath('data.rules.lead_tags', ['booked'])->assertJsonPath('data.rules.lead_steps', [])->assertJsonPath('data.rules.purchases', false);
        $this->putJson('/api/v1/conversions/rules', ['lead_tags' => [], 'lead_steps' => [['flow_id' => $flowId, 'step_id' => 'nope']], 'purchases' => false])->assertUnprocessable();
    }

    public function test_send_test_event_sends_one_lead_now_and_the_log_shows_it(): void
    {
        $account = $this->getJson('/api/v1/conversions')->json('data.accounts.0.id');
        $chat = $this->fromAd();
        $organic = $this->fromAd('971502222222', null);

        // Reporting off: refused.
        $this->postJson('/api/v1/conversions/test-event', ['conversation_id' => $chat])->assertUnprocessable();
        $this->postJson("/api/v1/conversions/accounts/{$account}/reporting", ['enabled' => true])->assertOk();

        $this->getJson('/api/v1/conversions/test-chats')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.conversation_id', $chat)->assertJsonPath('data.0.contact', 'Sara Ahmed')->assertJsonPath('data.0.ad.headline', 'Free trial class');
        $this->postJson('/api/v1/conversions/test-event', ['conversation_id' => $organic])->assertUnprocessable(); // no click ID

        $this->postJson('/api/v1/conversions/test-event', ['conversation_id' => $chat])->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.status_label', 'Sent')->assertJsonPath('data.meta_response.events_received', 1);
        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/1503923451234567/events')
            && $r['data'][0]['event_name'] === 'LeadSubmitted' && $r['data'][0]['user_data']['ctwa_clid'] === 'ARAkLkA8rmlFeiCktEJQ');

        $this->getJson('/api/v1/conversions/events')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'LeadSubmitted')->assertJsonPath('data.0.status_label', 'Sent')->assertJsonPath('data.0.is_test', true)
            ->assertJsonPath('data.0.contact.name', 'Sara Ahmed')->assertJsonPath('data.0.ad.headline', 'Free trial class');
    }

    public function test_the_log_filters_by_status_and_date_and_shows_the_reason(): void
    {
        $chat = $this->fromAd();
        $contact = $this->tenantContext()->bypass(fn () => Contact::query()->value('id'));
        $this->tenantContext()->bypass(function () use ($chat, $contact) {
            foreach ([['sent', now()->subDays(1), null], ['skipped', now()->subDays(3), 'Conversion reporting is off for this WhatsApp account.'], ['failed', now(), 'A purchase needs its currency and value.']] as [$status, $at, $reason]) {
                DB::table('conversion_events')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'conversation_id' => $chat, 'contact_id' => $contact,
                    'event_name' => 'Purchase', 'event_time' => $at, 'value' => 120, 'currency' => 'AED', 'status' => $status, 'error_reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->getJson('/api/v1/conversions/events')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/conversions/events?status=skipped')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Conversion reporting is off for this WhatsApp account.')->assertJsonPath('data.0.status_label', 'Skipped')
            ->assertJsonPath('data.0.value', 120)->assertJsonPath('data.0.currency', 'AED');
        $this->getJson('/api/v1/conversions/events?from='.now()->subDays(2)->toDateString().'&to='.now()->toDateString())->assertJsonCount(2, 'data');
    }

    public function test_only_people_who_manage_integrations_change_anything(): void
    {
        $account = $this->getJson('/api/v1/conversions')->json('data.accounts.0.id');
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->postJson("/api/v1/conversions/accounts/{$account}/reporting", ['enabled' => true])->assertForbidden();
        $this->putJson('/api/v1/conversions/rules', ['lead_tags' => [], 'lead_steps' => [], 'purchases' => true])->assertForbidden();
        $this->postJson('/api/v1/conversions/test-event', ['conversation_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_growth_hub_shows_leads_purchases_and_revenue_per_ad_for_a_period(): void
    {
        $chat = $this->fromAd();
        $other = $this->fromAd('971503333333', 'ARB-second-click');
        $this->tenantContext()->bypass(function () use ($chat, $other) {
            DB::table('ad_clicks')->where('conversation_id', $other)->update(['source_id' => '120210000000000002', 'headline' => 'Summer offer']);
            $click = fn (string $c) => (string) DB::table('ad_clicks')->where('conversation_id', $c)->value('id');
            $rows = [
                [$chat, 'LeadSubmitted', null, now()->subDays(2), 'sent', false],
                [$chat, 'Purchase', 250, now()->subDay(), 'sent', false],
                [$chat, 'Purchase', 100, now()->subDays(1), 'queued', false],
                [$chat, 'LeadSubmitted', null, now(), 'sent', true],          // a test event never counts
                [$chat, 'Purchase', 999, now()->subDays(40), 'sent', false],  // outside 30 days
                [$other, 'LeadSubmitted', null, now()->subDays(3), 'skipped', false],
            ];
            foreach ($rows as [$conv, $event, $value, $at, $status, $test]) {
                DB::table('conversion_events')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'conversation_id' => $conv, 'ad_click_id' => $click($conv),
                    'event_name' => $event, 'event_time' => $at, 'value' => $value, 'currency' => $value !== null ? 'AED' : null, 'status' => $status, 'is_test' => $test,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->getJson('/api/v1/growth/conversions')->assertOk()
            ->assertJsonPath('data.totals.leads', 2)->assertJsonPath('data.totals.purchases', 2)
            ->assertJsonPath('data.totals.revenue', [['currency' => 'AED', 'value' => 350]])->assertJsonPath('data.totals.sent_to_meta', 2)
            ->assertJsonPath('data.ads.0.source_id', '120210000000000001')->assertJsonPath('data.ads.0.headline', 'Free trial class')
            ->assertJsonPath('data.ads.0.leads', 1)->assertJsonPath('data.ads.0.purchases', 2)->assertJsonPath('data.ads.0.revenue.0.value', 350)
            ->assertJsonPath('data.ads.1.headline', 'Summer offer')->assertJsonPath('data.ads.1.leads', 1)->assertJsonPath('data.ads.1.purchases', 0)
            ->assertJsonMissingPath('data.ads.0.cost_per_result');

        $this->getJson('/api/v1/growth/conversions?days=90')->assertJsonPath('data.totals.purchases', 3)->assertJsonPath('data.totals.revenue.0.value', 1349);
        $day = now()->subDays(2)->toDateString();
        $this->getJson("/api/v1/growth/conversions?since={$day}&until={$day}")->assertJsonPath('data.totals.leads', 1)->assertJsonPath('data.totals.purchases', 0);

        $this->tenantContext()->run($this->tenant, fn () => ConversionsAccess::set($this->tenant, false));
        $this->getJson('/api/v1/growth/conversions')->assertNotFound();
    }
}
