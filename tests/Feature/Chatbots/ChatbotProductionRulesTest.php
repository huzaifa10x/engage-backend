<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Access\Models\Role;
use App\Domain\Access\Permission;
use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Chatbots\Services\ChatbotWorkspace;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * The Flow Builder's production rules (decisions recorded in the Flow Builder report, “Engine
 * compatibility”): options saved to contact fields, day-first dates, number shorthands, the 24-hour
 * window (step fallback, flow template, then handover), Meta-ad routing from verified attribution,
 * outcomes on events, and a Test chat that never reaches real systems unless explicitly allowed.
 *
 * Everything runs through the real stack: webhook → inbox → Chatbots → FlowEngine → LiveRuntime →
 * SendMessage → (faked) WhatsApp Cloud API, with sessions, events, notes and contacts in PostgreSQL.
 */
final class ChatbotProductionRulesTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> messages sent to Meta */
    private array $sent = [];

    /** @var list<string> URLs of outside API / Sheets calls actually made */
    private array $outside = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*/messages*' => function (Request $r) {
                $this->sent[] = $r->data();

                return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]]);
            },
            'api.example.com/*' => function (Request $r) {
                $this->outside[] = $r->url();

                return Http::response(['lead' => ['id' => 'L-1']]);
            },
            'script.google.com/*' => function (Request $r) {
                $this->outside[] = $r->url();

                return Http::response(['ok' => true]);
            },
        ]);
        foreach (['area' => 'text', 'budget' => 'number', 'stage' => 'text'] as $key => $type) {
            $this->inTenant(fn () => ContactField::query()->create(['key' => $key, 'label' => ucfirst($key), 'type' => $type]));
        }
    }

    /** @template T @param callable(): T $fn @return T */
    private function inTenant(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    /** @param array<string, mixed> $message */
    private function customer(array $message): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue($message, bsuid: null)))->assertOk();
    }

    private function says(string $text): void
    {
        $this->customer(['text' => ['body' => $text]]);
    }

    private function picks(string $id, string $title): void
    {
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => $title]]]);
    }

    /** @param array<string, mixed> $graph @param array<string, mixed> $settings */
    private function liveFlow(array $graph, array $settings = []): ChatbotFlow
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", array_filter(['draft' => $graph, 'settings' => $settings ?: null]))->assertOk();
        $publish = $this->postJson("/api/v1/chatbots/{$id}/publish");
        $this->assertSame(200, $publish->status(), (string) json_encode($publish->json('error.details.issues') ?? $publish->json(), JSON_UNESCAPED_UNICODE));
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();

        return $this->inTenant(fn () => ChatbotFlow::query()->findOrFail($id));
    }

    private function botSession(): ChatbotSession
    {
        return $this->inTenant(fn () => ChatbotSession::query()->latest('created_at')->firstOrFail());
    }

    private function contact(): Contact
    {
        return $this->inTenant(fn () => Contact::query()->sole());
    }

    private function approvedTemplate(string $name): void
    {
        $this->inTenant(fn () => MessageTemplate::query()->create([
            'waba_account_id' => $this->number->waba_account_id, 'name' => $name, 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
            'components' => [['type' => 'BODY', 'text' => 'New listings are in. Reply to continue.']],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function node(string $id, string $type, array $config, array $outputs = []): array
    {
        return ['id' => $id, 'type' => $type, 'title' => ucfirst($id), 'config' => $config, 'outputs' => array_map(fn ($to, $out) => ['id' => $out, 'to' => $to], $outputs, array_keys($outputs))];
    }

    private function handoff(): array
    {
        return $this->node('team', 'handoff', ['text' => 'A person will reply.', 'outcome' => 'Qualified lead']);
    }

    public function test_a_tapped_option_is_saved_to_the_contact_field(): void
    {
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'area']),
            $this->node('area', 'list', ['text' => 'Which area?', 'button_label' => 'Choose', 'save_to' => 'field:area', 'sections' => [['title' => null, 'rows' => [['id' => 'jvc', 'title' => 'JVC'], ['id' => 'marina', 'title' => 'Dubai Marina']]]]], ['jvc' => 'thanks', 'marina' => 'thanks']),
            $this->node('thanks', 'message', ['text' => 'Great, {{contact.area}} it is.'], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('hi');
        $this->picks('bot:area:marina', 'Dubai Marina');

        $this->assertSame('Dubai Marina', $this->contact()->custom_fields['area'] ?? null, 'saved on the contact (CRM)');
        $this->assertSame('Great, Dubai Marina it is.', $this->sent[1]['text']['body'] ?? null);
        $this->assertSame('handed_off', $this->botSession()->status);
    }

    public function test_dates_are_day_first_validated_and_shown_back_readably(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Dubai')->setDate(2026, 11, 12)->setTime(13, 0));
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'day']),
            $this->node('day', 'question', ['text' => 'Which day?', 'validate' => 'future_date', 'window_days' => 30, 'save_to' => 'var:day', 'retries' => 5], ['next' => 'ok']),
            $this->node('ok', 'message', ['text' => 'Booked for {{var.day}}.'], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('hi');
        $this->says('31/4');
        $this->says('11/18');
        $this->says('15/2/2027');
        $this->says('18/11');

        $texts = array_map(fn ($m) => $m['text']['body'] ?? null, $this->sent);
        $this->assertSame([
            'Which day?',
            'Sorry, that date doesn’t exist. Please check the day and month.', 'Which day?',
            'Sorry, please write the day first, for example 18/11.', 'Which day?',
            'Sorry, please choose a date within the next 30 days.', 'Which day?',
            'Booked for Wed 18 Nov 2026.', 'A person will reply.',
        ], $texts);
        $this->assertSame('2026-11-18', $this->botSession()->vars['day'], 'stored as a date, not as text');
    }

    public function test_numbers_accept_k_and_m(): void
    {
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'budget']),
            $this->node('budget', 'question', ['text' => 'Budget?', 'validate' => 'number', 'min' => 300000, 'save_to' => 'field:budget'], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('hi');
        $this->says('AED 1.2m');

        $this->assertSame('1200000', (string) $this->contact()->custom_fields['budget']);
    }

    public function test_a_closed_window_sends_the_steps_fallback_template_and_runs_the_step_when_the_customer_answers(): void
    {
        $this->approvedTemplate('property_followup');
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'wait']),
            $this->node('wait', 'delay', ['minutes' => 2880], ['next' => 'follow']),
            $this->node('follow', 'buttons', ['text' => 'Want an agent to call?', 'buttons' => [['id' => 'y', 'title' => 'Yes please']], 'fallback_template' => ['name' => 'property_followup', 'language' => 'en', 'body' => []]], ['y' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('hi');
        $this->inTenant(fn () => Conversation::query()->update(['window_expires_at' => now()->addDays(2)->subMinute()]));
        $this->travel(2881)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();

        $this->assertSame('template', $this->sent[0]['type'] ?? null);
        $this->assertSame('property_followup', $this->sent[0]['template']['name'] ?? null);
        $this->assertTrue($this->botSession()->awaiting_input);

        $this->says('hello again');
        $this->assertSame('Want an agent to call?', $this->sent[1]['interactive']['body']['text'] ?? null, 'the step runs once the customer answers');
    }

    public function test_when_no_template_can_go_out_the_chat_goes_to_the_team_with_a_note(): void
    {
        // The fallback template was approved when the flow was published (a paused one is refused by the
        // publish checks), and Meta paused it afterwards.
        $this->approvedTemplate('paused_one');
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'wait']),
            $this->node('wait', 'delay', ['minutes' => 2880], ['next' => 'follow']),
            $this->node('follow', 'message', ['text' => 'Still interested?', 'fallback_template' => ['name' => 'paused_one', 'language' => 'en', 'body' => []]], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('hi');
        $this->inTenant(fn () => MessageTemplate::query()->where('name', 'paused_one')->update(['status' => 'PAUSED']));
        $this->inTenant(fn () => Conversation::query()->update(['window_expires_at' => now()->addDays(2)->subMinute()]));
        $this->travel(2881)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();

        $this->assertSame([], $this->sent, 'nothing is sent');
        $this->assertSame('handed_off', $this->botSession()->status);
        $this->assertSame('template_unavailable', $this->botSession()->ended_reason);
        $this->assertStringContainsString('template isn’t approved', (string) $this->inTenant(fn () => ConversationNote::query()->latest('created_at')->value('body')));
    }

    public function test_meta_ad_routing_uses_the_ad_referral_on_the_message(): void
    {
        $graph = ['nodes' => [
            $this->node('start', 'start', ['any_message' => true, 'ad_referral' => true], ['next' => 'ad']),
            $this->node('ad', 'condition', ['match' => 'all', 'rules' => [['field' => 'lead_source', 'op' => 'equals', 'value' => 'meta_ad']]], ['yes' => 'offer', 'no' => 'plain']),
            $this->node('offer', 'message', ['text' => 'You came from “{{contact.campaign}}”: free valuation!'], ['next' => 'team']),
            $this->node('plain', 'message', ['text' => 'Welcome!'], ['next' => 'team']),
            $this->handoff(),
        ]];
        $this->liveFlow($graph);
        $this->customer(['text' => ['body' => 'Is this still available?'], 'referral' => ['source_type' => 'ad', 'source_id' => '120200000001', 'source_url' => 'https://fb.me/abc', 'headline' => 'JVC villas from 2.1M']]);

        $this->assertSame('You came from “JVC villas from 2.1M”: free valuation!', $this->sent[0]['text']['body'] ?? null);
        $this->assertSame(['source' => 'meta_ad', 'campaign' => 'JVC villas from 2.1M', 'evidence' => 'meta_referral'], $this->botSession()->vars['_source']);
    }

    public function test_an_organic_message_never_routes_as_an_ad_lead(): void
    {
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'ad']),
            $this->node('ad', 'condition', ['match' => 'all', 'rules' => [['field' => 'lead_source', 'op' => 'equals', 'value' => 'meta_ad']]], ['yes' => 'offer', 'no' => 'plain']),
            $this->node('offer', 'message', ['text' => 'Ad offer'], ['next' => 'team']),
            $this->node('plain', 'message', ['text' => 'Welcome!'], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('Hi, I saw you on Facebook'); // a claim is not evidence

        $this->assertSame('Welcome!', $this->sent[0]['text']['body'] ?? null);
    }

    public function test_growth_evidence_on_the_contact_counts_as_the_lead_source(): void
    {
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'src']),
            $this->node('src', 'condition', ['match' => 'all', 'rules' => [['field' => 'lead_source', 'op' => 'in', 'value' => 'google_ads, paid_other']]], ['yes' => 'paid', 'no' => 'plain']),
            $this->node('paid', 'message', ['text' => 'Paid lead: {{contact.campaign}}'], ['next' => 'team']),
            $this->node('plain', 'message', ['text' => 'Welcome!'], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('hi');
        // The customer's next conversation, after the Growth Hub proved a Google Ads click.
        $this->inTenant(function (): void {
            Contact::query()->update(['acquisition_latest' => DB::raw("'".json_encode(['source' => 'google_ads', 'label' => 'Google Ads', 'campaign' => 'villas_winter', 'type' => 'website_reference'])."'::jsonb")]);
            ChatbotSession::query()->update(['status' => 'completed']);
            Conversation::query()->update(['handler' => 'bot', 'bot_session_id' => null, 'bot_state' => null]);
        });
        $this->sent = [];
        $this->says('hello again');

        $this->assertSame('Paid lead: villas_winter', $this->sent[0]['text']['body'] ?? null);
    }

    public function test_handover_and_end_record_their_outcome_for_insights(): void
    {
        $flow = $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->says('hi');

        $this->assertTrue($this->inTenant(fn () => ChatbotEvent::query()->where('flow_id', $flow->id)->where('kind', 'handoff')->where('detail', 'Qualified lead')->exists()));
    }

    public function test_the_test_chat_can_start_at_a_chosen_step(): void
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'hello']),
            $this->node('hello', 'message', ['text' => 'Welcome!'], ['next' => 'prices']),
            $this->node('prices', 'message', ['text' => 'Our prices start at AED 349.'], ['next' => 'team']),
            $this->handoff(),
        ]]])->assertOk();

        $text = fn (array $t) => collect($t)->map(fn ($e) => $e['message']['body'] ?? $e['text'] ?? '')->implode("\n");
        $t = $text($this->postJson("/api/v1/chatbots/{$id}/test", ['from_node' => 'prices'])->assertOk()->json('data.transcript'));
        $this->assertStringNotContainsString('Welcome!', $t);
        $this->assertStringContainsString('Our prices start at AED 349.', $t);
        $this->assertStringContainsString('Started at “', $t);

        // An unknown step, or the Start step, starts at the beginning as usual.
        $this->assertStringContainsString('Welcome!', $text($this->postJson("/api/v1/chatbots/{$id}/test", ['from_node' => 'nope'])->assertOk()->json('data.transcript')));
        $this->postJson("/api/v1/chatbots/{$id}/test", ['from_node' => '../etc'])->assertUnprocessable();
    }

    public function test_the_test_chat_never_calls_apis_or_sheets_unless_a_publisher_allows_it(): void
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'crm']),
            $this->node('crm', 'http', ['method' => 'POST', 'url' => 'https://api.example.com/leads', 'body' => '{"phone":"{{contact.phone}}"}', 'save' => [['path' => 'lead.id', 'to' => 'var:lead']], 'sample_status' => 201, 'sample_body' => '{"lead":{"id":"SAMPLE-7"}}'], ['success' => 'sheet', 'error' => 'failed']),
            $this->node('sheet', 'sheets', ['webapp_url' => 'https://script.google.com/macros/s/X/exec', 'sheet' => 'Leads', 'columns' => [['name' => 'Lead', 'value' => '{{var.lead}}']]], ['next' => 'done']),
            $this->node('done', 'message', ['text' => 'Saved as {{var.lead}}'], ['next' => 'team']),
            $this->node('failed', 'message', ['text' => 'The CRM is down'], ['next' => 'team']),
            $this->handoff(),
        ]]])->assertOk();

        $t = $this->postJson("/api/v1/chatbots/{$id}/test", [])->assertOk()->json('data.transcript');
        $this->assertSame([], $this->outside, 'nothing left the system');
        $texts = collect($t)->map(fn ($e) => $e['message']['body'] ?? $e['text'] ?? '')->implode("\n");
        $this->assertStringContainsString('(Test) Not sent: would call POST api.example.com/leads', $texts);
        $this->assertStringContainsString('Saved as SAMPLE-7', $texts, 'the step’s sample answer drives the test');
        $this->assertStringContainsString('(Test) Not sent: would add a row to “Leads”', $texts);

        $t = $this->postJson("/api/v1/chatbots/{$id}/test", ['api_mode' => 'error'])->assertOk()->json('data.transcript');
        $this->assertStringContainsString('The CRM is down', collect($t)->map(fn ($e) => $e['message']['body'] ?? '')->implode("\n"));
        $this->assertSame([], $this->outside);

        $live = ['integrations' => 'live', 'confirm_live' => true, 'live_reason' => 'Check the sandbox CRM mapping'];

        // Real calls: refused to someone who can't publish …
        $viewer = $this->addMember($this->tenant, SystemRole::Viewer);
        $this->actingAsMember($viewer);
        $this->postJson("/api/v1/chatbots/{$id}/test", $live)->assertForbidden();
        // … and to a publisher who can't manage integrations (publishing alone is not enough).
        $publisherRole = $this->tenantContext()->bypass(fn () => Role::query()->create([
            'tenant_id' => $this->tenant->id, 'key' => 'flow_publisher', 'name' => 'Flow publisher', 'is_system' => false,
            'permissions' => [Permission::ChatbotsView->value, Permission::ChatbotsManage->value, Permission::ChatbotsPublish->value],
        ]));
        $publisher = $this->addMember($this->tenant, SystemRole::Viewer);
        $this->tenantContext()->bypass(fn () => $publisher->forceFill(['role_id' => $publisherRole->id])->save());
        $this->actingAsMember($publisher);
        $this->postJson("/api/v1/chatbots/{$id}/test", $live)->assertForbidden();
        $this->assertSame([], $this->outside);

        // An owner must confirm and give a reason …
        $this->actingAsMember($this->owner);
        $this->postJson("/api/v1/chatbots/{$id}/test", ['integrations' => 'live'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['confirm_live', 'live_reason']]]]);
        // … and the workspace must have test endpoints.
        $this->postJson("/api/v1/chatbots/{$id}/test", $live)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['integrations']]]]);
        $this->assertSame([], $this->outside);

        // Test endpoints: https host names only; only people who manage integrations may set them.
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['http://10.0.0.5/hook']])->assertUnprocessable();
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://user:pw@api.example.com/']])->assertUnprocessable();
        $this->actingAsMember($publisher);
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://api.example.com/']])->assertForbidden();
        $this->actingAsMember($this->owner);
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://API.example.com/leads']])->assertOk()
            ->assertJsonPath('data.test_endpoints', ['https://api.example.com/leads']);

        // Only the listed endpoint is really called; the sheet (not listed) stays a dry run.
        $t = $this->postJson("/api/v1/chatbots/{$id}/test", $live)->assertOk()->json('data.transcript');
        $this->assertCount(1, $this->outside, 'only the test endpoint was called');
        $this->assertStringContainsString('api.example.com/leads', (string) $this->outside[0]);
        $texts = collect($t)->map(fn ($e) => $e['message']['body'] ?? $e['text'] ?? '')->implode("\n");
        $this->assertStringContainsString('(Test) Not sent: would add a row to “Leads”', $texts);
        $this->inTenant(function () {
            $this->assertSame('Check the sandbox CRM mapping', json_decode((string) DB::table('audit_log')->where('action', 'chatbot.test_live_calls')->value('meta'), true)['reason']);
            $call = json_decode((string) DB::table('audit_log')->where('action', 'chatbot.test_live_call')->sole()->meta, true);
            $this->assertSame(['api', 'POST', 'api.example.com', '/leads', 'crm'], [$call['kind'], $call['method'], $call['host'], $call['path'], $call['node']]);
        });

        // A look-alike path is not the endpoint ("/leads-prod" is not under "/leads").
        $this->assertFalse(ChatbotWorkspace::isTestEndpoint('https://api.example.com/leads-prod', ['https://api.example.com/leads']));
        $this->assertTrue(ChatbotWorkspace::isTestEndpoint('https://api.example.com/leads/42', ['https://api.example.com/leads']));
        $this->assertFalse(ChatbotWorkspace::isTestEndpoint('https://api.example.com.evil.io/leads', ['https://api.example.com/leads']));

        // 30 live tests per person per hour.
        $key = 'chatbot-live-test:'.$this->tenant->id.':'.$this->owner->user_id;
        foreach (range(1, 29) as $_) {
            RateLimiter::hit($key, 3600);
        }
        $this->postJson("/api/v1/chatbots/{$id}/test", $live)->assertUnprocessable();
        $this->assertSame([], $this->sent, 'tests never message customers');
    }

    public function test_the_test_chat_behaves_like_live_for_names_templates_and_the_window(): void
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'name']),
            $this->node('name', 'question', ['text' => 'Your name?', 'validate' => 'text', 'save_to' => 'name'], ['next' => 'thanks']),
            $this->node('thanks', 'message', ['text' => 'Thanks {{contact.first_name}}!', 'fallback_template' => ['name' => 'missing_one', 'language' => 'en', 'body' => []]], ['next' => 'team']),
            $this->handoff(),
        ]]])->assertOk();
        $this->inTenant(fn () => MessageTemplate::query()->create(['waba_account_id' => $this->number->waba_account_id, 'name' => 'other', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'x']]]));

        $r = $this->postJson("/api/v1/chatbots/{$id}/test", [])->assertOk()->json('data');
        $r = $this->postJson("/api/v1/chatbots/{$id}/test", ['test_id' => $r['test_id'], 'text' => 'Mona Saleh'])->assertOk()->json('data');
        $this->assertContains('Thanks Mona!', collect($r['transcript'])->pluck('message.body')->all(), 'first name refreshed like live');

        // Window closed in the test settings and the fallback template doesn't exist: handed over, as live.
        $r = $this->postJson("/api/v1/chatbots/{$id}/test", ['window' => 'closed'])->assertOk()->json('data');
        $this->assertSame('handed_off', $r['state']['status']);
    }

    public function test_another_workspace_can_neither_see_nor_trigger_this_workspaces_flows(): void
    {
        $flow = $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'hi']),
            $this->node('hi', 'message', ['text' => 'Hello from workspace A'], ['next' => 'team']),
            $this->handoff(),
        ]]);
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://api.example.com/']])->assertOk();

        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $intruder = $this->addMember($other);
        $this->connectNumber($other, wabaId: '202290129340399', phoneNumberId: '206540352242923', display: '+971 58 000 0002');
        $this->actingAsMember($intruder);

        // Through the API: workspace A's flow does not exist for workspace B.
        $this->getJson("/api/v1/chatbots/{$flow->id}")->assertNotFound();
        $this->postJson("/api/v1/chatbots/{$flow->id}/test", [])->assertNotFound();
        $this->postJson("/api/v1/chatbots/{$flow->id}/publish")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/chatbots')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/chatbots/workspace')->assertOk()->json('data.test_endpoints'), 'settings are per workspace');

        // Through WhatsApp: a message to workspace B's number never starts workspace A's bot.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'hi']], waId: '971509999999', bsuid: null, phoneNumberId: '206540352242923'), '202290129340399'))->assertOk();
        $this->assertSame([], $this->sent);
        $this->assertSame(0, $this->inTenant(fn () => ChatbotSession::query()->count()));
        $this->assertSame(0, $this->tenantContext()->run($other, fn () => ChatbotSession::query()->count()));
        // Workspace A's own customers still get it.
        $this->says('hi');
        $this->assertSame('Hello from workspace A', $this->sent[0]['text']['body'] ?? null);
    }
}
