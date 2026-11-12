<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Messaging\Models\Message;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * The 44-step property flow (Flow Builder template “Property enquiries”), published and live on a
 * number, driven by WhatsApp webhooks through the real stack: inbox, Chatbots, FlowEngine,
 * LiveRuntime, SendMessage and the WhatsApp Cloud API (faked at its HTTP endpoint), the CRM API and
 * Google Sheets (faked at their URLs), and the scheduler (engage:chatbots:tick). Every assertion reads
 * persisted state: chatbot_sessions, chatbot_events, contacts, conversations, notes and messages.
 */
final class ChatbotRealEstateJourneyTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var array<string, mixed> */
    private array $fixture;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<array{url: string, body: array<mixed>}> */
    private array $crm = [];

    /** @var list<array<mixed>> */
    private array $sheet = [];

    private int $crmStatus = 201;

    private ChatbotFlow $flow;

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
                $this->crm[] = ['url' => $r->url(), 'body' => $r->data()];

                return Http::response($this->crmStatus < 300 ? ['lead' => ['id' => 'L-20481']] : ['error' => 'down'], $this->crmStatus);
            },
            'script.google.com/*' => function (Request $r) {
                $this->sheet[] = $r->data();

                return Http::response(['ok' => true]);
            },
        ]);
        foreach (['area' => 'text', 'property_type' => 'text', 'budget' => 'number', 'move_in' => 'text', 'stage' => 'text'] as $key => $type) {
            $this->inTenant(fn () => ContactField::query()->create(['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'type' => $type]));
        }
        $this->inTenant(fn () => MessageTemplate::query()->create([
            'waba_account_id' => $this->number->waba_account_id, 'name' => 'property_followup', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
            'components' => [['type' => 'BODY', 'text' => 'New listings came in. Reply to see them.']],
        ]));
        $this->fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/chatbots/real-estate-44.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->flow = $this->publish($this->fixture['graph']);
    }

    /** @template T @param callable(): T $fn @return T */
    private function inTenant(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    /** Creates the flow as the builder does: draft saved, published (version 1), switched on. @param array<string, mixed> $graph */
    private function publish(array $graph): ChatbotFlow
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Property enquiries', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => $graph])->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk()->assertJsonPath('data.published_version', 1);
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk()->assertJsonPath('data.status', 'live');

        return $this->inTenant(fn () => ChatbotFlow::query()->findOrFail($id));
    }

    /**
     * A WhatsApp message. WhatsApp sends the sender's profile name, and production treats it as the
     * contact's name (LiveRuntime::snapshot: name ?? profile_name), so the “Known contact?” step skips
     * the name question. $profile = '' is a customer whose profile name WhatsApp didn't send.
     *
     * @param  array<string, mixed>  $message
     */
    private function customer(array $message, string $waId = '971501234567', string $profile = 'Sara Ahmed'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue($message, waId: $waId, bsuid: null, name: $profile)))->assertOk();
    }

    private function says(string $text): void
    {
        $this->customer(['text' => ['body' => $text]]);
    }

    /** Taps a button or a list row of a step, found by its title in the flow. */
    private function taps(string $nodeId, string $title): void
    {
        $node = collect($this->fixture['graph']['nodes'])->firstWhere('id', $nodeId);
        $choices = $node['type'] === 'list' ? array_merge(...array_map(fn ($s) => $s['rows'], $node['config']['sections'])) : $node['config']['buttons'];
        $choice = collect($choices)->firstWhere('title', $title);
        $this->assertNotNull($choice, "“{$title}” is an option of {$nodeId}");
        $kind = $node['type'] === 'list' ? 'list_reply' : 'button_reply';
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => "bot:{$nodeId}:{$choice['id']}", 'title' => $title]]]);
    }

    /** @param callable(array<string, mixed>): void $as */
    private function tapAs(callable $as, string $nodeId, string $title): void
    {
        $node = collect($this->fixture['graph']['nodes'])->firstWhere('id', $nodeId);
        $choices = $node['type'] === 'list' ? array_merge(...array_map(fn ($s) => $s['rows'], $node['config']['sections'])) : $node['config']['buttons'];
        $choice = collect($choices)->firstWhere('title', $title);
        $kind = $node['type'] === 'list' ? 'list_reply' : 'button_reply';
        $as(['type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => "bot:{$nodeId}:{$choice['id']}", 'title' => $title]]]);
    }

    private function tick(int $minutes): void
    {
        $this->travel($minutes)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();
    }

    /** The newest bot session of a customer (by WhatsApp number). */
    private function botSession(string $waId = '971501234567'): ChatbotSession
    {
        return $this->inTenant(fn () => ChatbotSession::query()->whereIn('contact_id', Contact::query()->where('wa_id', $waId)->select('id'))->latest('created_at')->firstOrFail());
    }

    private function contact(string $waId = '971501234567'): Contact
    {
        return $this->inTenant(fn () => Contact::query()->where('wa_id', $waId)->firstOrFail());
    }

    /** @return list<string> the step ids the session entered, in order */
    private function path(?ChatbotSession $s = null): array
    {
        $s ??= $this->botSession();

        return $this->inTenant(fn () => ChatbotEvent::query()->where('session_id', $s->id)->where('kind', 'enter')->orderBy('created_at')->orderBy('id')->pluck('node_id')->all());
    }

    private function lastNote(): string
    {
        return (string) $this->inTenant(fn () => ConversationNote::query()->latest('created_at')->value('body'));
    }

    /** A buyer whose WhatsApp profile name is known (the usual case): no name question. */
    private function buyer(string $budget, string $area = 'Dubai Marina', string $type = 'Apartment', string $beds = '2 bedrooms'): void
    {
        $this->says('Hi');
        $this->taps('re_welcome', 'Buy');
        $this->taps('re_area', $area);
        $this->taps('re_type', $type);
        $this->taps('re_beds', $beds);
        $this->says($budget);
    }

    public function test_premium_buyer_books_a_viewing_and_reaches_the_manager_with_everything_saved(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Dubai')->setDate(2026, 11, 12)->setTime(13, 0));
        $this->buyer('2,500,000');
        $this->taps('re_finance', 'Cash');
        $this->taps('re_ready', 'Ready');
        $this->taps('re_viewing', 'Yes, book a viewing');
        $this->says('31/11'); // doesn't exist: asked again
        $this->says('21/11');
        $this->taps('re_vtime', 'Evening');

        $s = $this->botSession();
        $this->assertSame('handed_off', $s->status);
        $this->assertSame('re_h_mgr', $s->ended_node);
        $c = $this->contact();
        $this->assertNotContains('re_name', $this->path($s), 'the WhatsApp profile name counts as a known name');
        $this->assertEqualsCanonicalizing(['area' => 'Dubai Marina', 'property_type' => 'Apartment', 'budget' => '2500000', 'stage' => 'Qualified'], array_intersect_key(array_map('strval', (array) $c->custom_fields), array_flip(['area', 'property_type', 'budget', 'stage'])));
        $this->assertEqualsCanonicalizing(['buyer', 'premium', 'organic-lead'], (array) $c->tags);
        $this->assertSame('2026-11-21', $s->vars['viewing_date']);
        // The CRM got the lead once, with the answers; the sheet got one row.
        $this->assertCount(1, $this->crm);
        $this->assertSame(['phone' => '+971501234567', 'area' => 'Dubai Marina', 'budget' => '2500000'], $this->crm[0]['body']);
        $this->assertSame('L-20481', $s->vars['crm_id']);
        $this->assertCount(1, $this->sheet);
        $this->assertSame(['Name' => 'Sara Ahmed', 'Area' => 'Dubai Marina', 'Budget' => '2500000'], $this->sheet[0]['row']);
        // Agent handover: conversation leaves the bot, the team gets the answers, the outcome is recorded.
        $conv = $this->inTenant(fn () => Conversation::query()->sole());
        $this->assertSame('handed_off', $conv->bot_state);
        $this->assertNotSame('bot', $conv->handler);
        $this->assertStringContainsString('Premium buyer: budget AED 2500000, Ready.', (string) $this->inTenant(fn () => ConversationNote::query()->oldest('created_at')->value('body')));
        $this->assertTrue($this->inTenant(fn () => ConversationNote::query()->where('body', 'like', '%handed this conversation to the team%')->where('body', 'like', '%intent: Buy%')->exists()), 'the team gets the answers');
        $this->assertTrue($this->inTenant(fn () => ChatbotEvent::query()->where('session_id', $s->id)->where('kind', 'handoff')->where('detail', 'Premium lead')->exists()));
        $this->assertTrue($this->inTenant(fn () => ChatbotEvent::query()->where('session_id', $s->id)->where('kind', 'error')->where('detail', 'no_match')->exists()), 'the impossible date counted as a wrong answer');
        // Every message went through WhatsApp, recorded as an automation message on the conversation.
        $this->assertSame(count($this->sent), $this->inTenant(fn () => Message::query()->where('direction', Message::OUTBOUND)->count()));
        $this->assertContains('Sorry, that date doesn’t exist. Please check the day and month.', array_map(fn ($m) => $m['text']['body'] ?? null, $this->sent));
        $this->assertGreaterThanOrEqual(24, count(array_unique($this->path($s))));
    }

    public function test_renter_with_move_in_date_reaches_leasing_and_crm_failure_still_answers(): void
    {
        $this->crmStatus = 500;
        $lina = fn (array $m) => $this->customer($m, '971528804410', '');
        $lina(['text' => ['body' => 'Hi']]);
        $this->tapAs($lina, 're_welcome', 'Rent');
        $lina(['text' => ['body' => 'Lina Haddad']]);
        $this->tapAs($lina, 're_area', 'Other');
        $lina(['text' => ['body' => 'Al Barsha']]);
        $this->tapAs($lina, 're_type', 'Villa');
        $this->tapAs($lina, 're_beds', 'Studio');
        $lina(['text' => ['body' => '5000']]); // below the minimum: asked again
        $lina(['text' => ['body' => '120k']]);
        $this->tapAs($lina, 're_cheques', '4 cheques');
        $lina(['text' => ['body' => '01/02/2027']]);
        $this->tapAs($lina, 're_furnish', 'Furnished');
        $this->tapAs($lina, 're_viewing', 'Send listings first');

        $s = $this->botSession('971528804410');
        $this->assertSame('handed_off', $s->status);
        $this->assertSame('re_h_leasing', $s->ended_node);
        $this->assertContains('re_crm_fail', $this->path($s), 'the CRM error took the Error path');
        $this->assertStringContainsString('The CRM didn’t accept this lead', (string) $this->inTenant(fn () => ConversationNote::query()->where('body', 'like', '%CRM%')->value('body')));
        $this->assertSame('Lina Haddad', $this->contact('971528804410')->name, 'no profile name: the bot asked and saved it');
        $f = (array) $this->contact('971528804410')->custom_fields;
        $this->assertSame(['Al Barsha', 'Villa', '120000', '2027-02-01'], [$f['area'], $f['property_type'], (string) $f['budget'], $f['move_in']]);
        $this->assertSame(500, $s->vars['http_status']);
    }

    public function test_wrong_budget_three_times_and_the_agent_word_both_reach_a_person(): void
    {
        $this->buyer('a lot');
        $this->says('two million');
        $this->says('lots');
        $this->assertSame('handed_off', $this->botSession()->status, 'third wrong answer: handover (retry limit 2)');
        $this->assertStringContainsString('did not understand 3 answer(s) in “Purchase budget”', $this->lastNote());

        // A new chat later: “agent” in the middle of the questions.
        $this->inTenant(fn () => Conversation::query()->update(['handler' => 'bot', 'bot_session_id' => null, 'bot_state' => null]));
        $this->customer(['text' => ['body' => 'Hi']], '971509999999');
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'bot:re_welcome:w_rent', 'title' => 'Rent']]], '971509999999');
        $this->customer(['text' => ['body' => 'agent']], '971509999999');
        $s = $this->botSession('971509999999');
        $this->assertSame('handed_off', $s->status);
        $this->assertSame('re_h_sales', $s->ended_node, 'the flow’s first handover step');
    }

    public function test_no_reply_takes_the_steps_path_then_a_two_day_wait_reopens_the_window_with_the_template(): void
    {
        $this->buyer('900000', 'JVC', 'Apartment', 'Studio');
        $this->taps('re_finance', 'Cash');
        $this->taps('re_ready', 'Ready');
        $this->assertSame('re_viewing', $this->botSession()->current_node);

        $this->tick(13); // no flow reminder: the step has its own no-reply timer
        $this->tick(45); // 58 minutes: past the flow's 45-minute abandonment, still waiting
        $this->assertSame('re_viewing', $this->botSession()->current_node, 'abandonment waits for the step’s timer');
        $this->assertSame('active', $this->botSession()->status);
        $this->tick(3); // 61 minutes: the step’s “No reply” path
        $s = $this->botSession();
        $this->assertSame('waiting', $s->status, 'now in the 2-day wait');
        $this->assertContains('re_later', $this->path($s));

        $before = count($this->sent);
        $this->tick(2881);
        $s = $this->botSession();
        $this->assertSame('property_followup', $this->sent[$before]['template']['name'] ?? null, 'outside the window the step’s fallback template went out');
        $this->assertTrue($s->awaiting_input);

        $this->says('yes?');
        $this->assertSame('Hi Sara, new listings came in. Want an agent to call you?', end($this->sent)['interactive']['body']['text'] ?? null);
        $this->taps('re_follow', 'No thanks');
        $s = $this->botSession();
        $this->assertSame('completed', $s->status);
        $this->assertTrue($this->inTenant(fn () => ChatbotEvent::query()->where('session_id', $s->id)->where('kind', 'complete')->where('detail', 'Nurture')->exists()));
    }

    public function test_silence_on_a_question_gets_one_reminder_then_the_bot_stops_and_the_chat_stays_open(): void
    {
        $this->says('Hi');
        $this->taps('re_welcome', 'Buy');
        $this->tick(13);
        $this->assertSame('Are you still there? Just tap one of the options above to continue 🙂', end($this->sent)['text']['body'] ?? null);
        $this->tick(33);
        $s = $this->botSession();
        $this->assertSame('abandoned', $s->status);
        $this->assertSame('re_area', $s->ended_node, 'profile name known, so the area was the open question');
        $this->assertTrue($this->inTenant(fn () => ChatbotEvent::query()->where('session_id', $s->id)->where('kind', 'abandon')->exists()));
        $this->assertNotNull($this->inTenant(fn () => Conversation::query()->whereNull('closed_at')->first()), 'the conversation stays open for the team');
    }

    public function test_menu_restarts_back_asks_again_and_a_tap_on_an_earlier_menu_still_routes(): void
    {
        $this->says('Hi');
        $this->taps('re_welcome', 'Rent');
        $this->taps('re_area', 'JVC');
        $this->taps('re_type', 'Apartment');
        $this->says('back');
        $this->assertSame('re_type', $this->botSession()->current_node, '“back” asks Property type again');
        $this->taps('re_type', 'Villa');
        $this->taps('re_area', 'Downtown'); // an earlier menu, tapped again
        $this->assertSame('Downtown', $this->contact()->custom_fields['area']);
        $this->says('menu');
        $s = $this->botSession();
        $this->assertSame('re_welcome', $s->current_node, '“menu” starts again');
        $this->assertSame(['re_hours', 're_welcome'], array_slice($this->path($s), -2));
    }

    public function test_a_meta_ad_lead_is_tagged_from_the_verified_referral(): void
    {
        $this->customer(['text' => ['body' => 'Is this available?'], 'referral' => ['source_type' => 'ad', 'source_id' => '120200000099', 'source_url' => 'https://fb.me/x', 'headline' => 'JVC villas']]);
        $this->taps('re_welcome', 'Buy');
        $this->taps('re_area', 'JVC');
        $this->taps('re_type', 'Townhouse');
        $this->taps('re_beds', '3 bedrooms');
        $this->says('1.8m');
        $this->taps('re_finance', 'Mortgage');
        $this->taps('re_preapp', 'In progress');
        $this->taps('re_ready', 'Off-plan');
        $this->assertContains('meta-lead', (array) $this->contact()->tags);
        $this->assertNotContains('organic-lead', (array) $this->contact()->tags);
    }

    public function test_after_hours_the_note_comes_first(): void
    {
        $this->actingAsMember($this->owner);
        $week = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $week[$d] = ['open' => $d !== 'fri', 'from' => '09:00', 'to' => '18:00'];
        }
        $this->putJson('/api/v1/tenant/inbox-settings', ['business_hours' => ['enabled' => true, 'days' => $week]])->assertOk();
        $this->travelTo(now()->setTimezone('Asia/Dubai')->setDate(2026, 11, 13)->setTime(22, 40));
        $this->says('Hi');
        $this->assertSame('We’re closed right now, but I can take your details and an agent will reply when we open.', $this->sent[0]['text']['body'] ?? null);
        $this->assertSame(['start', 're_hours', 're_ah_msg', 're_welcome'], $this->path());
    }

    public function test_a_chat_in_progress_finishes_on_its_version_after_republishing(): void
    {
        $this->says('Hi');
        $this->taps('re_welcome', 'Buy');
        $graph = $this->fixture['graph'];
        foreach ($graph['nodes'] as &$n) {
            if ($n['id'] === 're_name') {
                $n['config']['text'] = 'What is your full name?';
            }
        }
        unset($n);
        $this->actingAsMember($this->owner);
        $this->patchJson("/api/v1/chatbots/{$this->flow->id}", ['draft' => $graph])->assertOk();
        $this->postJson("/api/v1/chatbots/{$this->flow->id}/publish")->assertOk()->assertJsonPath('data.published_version', 2);

        $this->assertSame(1, $this->botSession()->flow_version);
        $this->says('Sara');
        $this->assertSame(1, $this->botSession()->flow_version, 'the running chat stays on version 1');
        $this->assertSame(2, $this->inTenant(fn () => ChatbotFlowVersion::query()->where('flow_id', $this->flow->id)->count()));
        // A new customer gets version 2.
        $this->customer(['text' => ['body' => 'Hi']], '971508888888', '');
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'bot:re_welcome:w_buy', 'title' => 'Buy']]], '971508888888', '');
        $this->assertSame(2, $this->botSession('971508888888')->flow_version);
        $this->assertSame('What is your full name?', end($this->sent)['text']['body'] ?? null);
    }

    public function test_the_draft_keeps_sections_and_set_up_marks_when_saved_and_loaded(): void
    {
        $data = $this->getJson("/api/v1/chatbots/{$this->flow->id}")->assertOk()->json('data');
        $draft = $data['draft'];
        $this->assertCount(count($this->fixture['graph']['sections']), $draft['sections']);
        $this->assertSame('re_s_buy', collect($draft['nodes'])->firstWhere('id', 're_budget_buy')['section']);
        $this->assertTrue(collect($draft['nodes'])->firstWhere('id', 're_area')['setup'] ?? false);
        $this->assertCount(44, $draft['nodes']);
    }
}
