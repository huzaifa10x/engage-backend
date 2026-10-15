<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Application\Messaging\SendMessage;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class ChatbotsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> messages sent to Meta, in order */
    private array $sent = [];

    /** Runs once while the bot calls an API block (to take over "while the bot is busy"). */
    private ?\Closure $duringApiCall = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        // API-call blocks call a made-up public host.
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
                if ($this->duringApiCall !== null) {
                    ($this->duringApiCall)();
                    $this->duringApiCall = null;
                }

                return str_contains($r->url(), '/orders/404') ? Http::response(['error' => 'not found'], 404) : Http::response(['order' => ['status' => 'out for delivery']]);
            },
        ]);
    }

    /** @template T @param callable(): T $fn @return T */
    private function inTenant(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    /** @param array<string, mixed> $message */
    private function customer(array $message = [], string $waId = '971501234567', string $phoneNumberId = '106540352242922', string $wabaId = '102290129340398'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue($message, waId: $waId, bsuid: null, phoneNumberId: $phoneNumberId), $wabaId))->assertOk();
    }

    private function says(string $text): void
    {
        $this->customer(['text' => ['body' => $text]]);
    }

    private function taps(string $id, string $title): void
    {
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]]);
    }

    /** @return list<string> what the customer received, as text (body of each message) */
    private function received(): array
    {
        return array_map(fn (array $m) => (string) ($m['text']['body'] ?? $m['interactive']['body']['text'] ?? $m['image']['caption'] ?? $m['type']), $this->sent);
    }

    /** @param array<string, mixed>|null $graph */
    private function liveFlow(string $template = 'menu', ?array $graph = null, array $settings = []): ChatbotFlow
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Main bot', 'phone_number_id' => $this->number->id, 'template' => $template])->assertCreated()->json('data.id');
        if ($graph !== null || $settings !== []) {
            $this->patchJson("/api/v1/chatbots/{$id}", array_filter(['draft' => $graph, 'settings' => $settings ?: null]))->assertOk();
        }
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk()->assertJsonPath('data.published_version', 1);
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk()->assertJsonPath('data.status', 'live');

        return $this->inTenant(fn () => ChatbotFlow::query()->findOrFail($id));
    }

    private function conversation(): Conversation
    {
        return $this->inTenant(fn () => Conversation::query()->sole());
    }

    private function botSession(): ChatbotSession
    {
        return $this->inTenant(fn () => ChatbotSession::query()->latest('created_at')->firstOrFail());
    }

    public function test_the_message_lands_in_the_inbox_and_the_numbers_bot_answers_in_parallel(): void
    {
        $this->liveFlow();
        $this->says('hello');

        $conversation = $this->conversation();
        $this->assertSame('bot', $conversation->handler);
        $this->assertSame('running', $conversation->bot_state);
        $this->assertSame(1, $conversation->unread_count, 'the customer message is in the inbox like any other');
        $this->assertSame(["Hi Sara 👋 Welcome to {$this->tenant->name}! How can we help you today?"], $this->received());
        $this->assertSame(['Our services', 'Opening hours', 'Talk to a person'], array_column(array_column($this->sent[0]['interactive']['action']['buttons'], 'reply'), 'title'));

        // Bot messages show in the thread too; the inbox lists the conversation under "Bot".
        $this->inTenant(fn () => $this->assertSame(2, Message::query()->count()));
        $this->getJson('/api/v1/conversations?view=bot')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.handler', 'bot')->assertJsonPath('data.0.bot.flow_name', 'Main bot')->assertJsonPath('data.0.bot.node_title', 'Welcome menu');
    }

    public function test_only_the_number_with_a_live_bot_runs_it(): void
    {
        $this->liveFlow();
        $this->connectNumber($this->tenant, wabaId: '102290129340399', phoneNumberId: '222333444555666', display: '+971 4 000 0000');

        $this->customer(['text' => ['body' => 'hi']], phoneNumberId: '222333444555666', wabaId: '102290129340399');

        $this->assertSame([], $this->sent, 'the second number has no bot');
        $this->assertNull($this->conversation()->handler);
    }

    public function test_buttons_route_by_payload_or_text_and_unknown_answers_end_at_the_handoff(): void
    {
        $this->liveFlow();
        $this->says('hi');
        $this->taps('bot:welcome:hours', 'Opening hours');
        $this->assertSame('We are open Sunday to Thursday, 9:00 to 18:00.', $this->received()[1]);
        $this->assertSame('Can we help with anything else?', $this->received()[2]);

        $this->says('main menu'); // typed instead of tapped
        $this->assertSame("Hi Sara 👋 Welcome to {$this->tenant->name}! How can we help you today?", $this->received()[3]);

        // Two wrong answers get a retry each; the third connects to a person (the default "No match" handling).
        $this->says('pizza?');
        $this->says('still pizza');
        $this->assertSame("Sorry, I didn't catch that. Please choose one of the options.", $this->received()[4]);
        $this->says('pizza!!');
        $this->assertSame('Connecting you with our team 👩‍💼 Someone will reply shortly.', last($this->received()));

        $conversation = $this->conversation();
        $this->assertSame('human', $conversation->handler);
        $this->assertSame('handed_off', $conversation->bot_state);
        $count = count($this->sent);
        $this->says('hello?');
        $this->assertCount($count, $this->sent, 'the bot stays silent after a handoff');
    }

    public function test_an_agent_reply_takes_over_and_the_bot_stays_silent_until_handed_back(): void
    {
        $this->liveFlow();
        $this->says('hi');
        $conversation = $this->conversation();

        $this->actingAsMember($this->owner);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Hi Sara, I can help.'])->assertStatus(202);
        $this->assertSame('human', $this->conversation()->handler);
        $this->assertSame('handed_off', $this->botSession()->status);
        $this->assertSame('agent_replied', $this->botSession()->ended_reason);

        $count = count($this->sent);
        $this->taps('bot:welcome:hours', 'Opening hours');
        $this->assertCount($count, $this->sent, 'a tap on an old menu does not wake the bot');

        $this->postJson("/api/v1/conversations/{$conversation->id}/bot/hand-back")->assertOk()->assertJsonPath('meta.restarted', true)->assertJsonPath('data.handler', 'bot');
        $this->assertSame("Hi Sara 👋 Welcome to {$this->tenant->name}! How can we help you today?", last($this->received()));
    }

    public function test_an_agent_can_hand_a_conversation_the_bot_never_ran_in_to_the_bot(): void
    {
        // The customer wrote and an agent answered before any chatbot was live on the number.
        $this->says('hi');
        $conversation = $this->conversation();
        $this->actingAsMember($this->owner);
        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertOk()->assertJsonPath('data.bot_available', false);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Hi Sara, I can help.'])->assertStatus(202);
        $this->assertSame('human', $this->conversation()->handler);

        $this->liveFlow();
        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertOk()
            ->assertJsonPath('data.bot', null)->assertJsonPath('data.handler', 'human')->assertJsonPath('data.bot_available', true);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.0.bot_available', true);

        $this->postJson("/api/v1/conversations/{$conversation->id}/bot/hand-back")->assertOk()->assertJsonPath('meta.restarted', true)->assertJsonPath('data.handler', 'bot');
        $this->assertSame("Hi Sara 👋 Welcome to {$this->tenant->name}! How can we help you today?", last($this->received()));
    }

    public function test_a_bot_reply_prepared_while_an_agent_takes_over_is_discarded(): void
    {
        $graph = ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'lookup']]],
            ['id' => 'lookup', 'type' => 'http', 'config' => ['method' => 'GET', 'url' => 'https://api.example.com/orders/1'], 'outputs' => [['id' => 'success', 'to' => 'status'], ['id' => 'error', 'to' => 'handoff']]],
            ['id' => 'status', 'type' => 'message', 'config' => ['text' => 'Your order is on its way'], 'outputs' => [['id' => 'next', 'to' => 'handoff']]],
            ['id' => 'handoff', 'type' => 'handoff', 'config' => ['text' => 'A person will help you'], 'outputs' => []],
        ]];
        $this->liveFlow('blank', $graph);

        // The agent answers while the bot waits for the slow API.
        $this->duringApiCall = function (): void {
            $this->inTenant(fn () => app(SendMessage::class)->toConversation(Conversation::query()->sole(), ['type' => 'text', 'body' => 'I am on it!'], MessageOrigin::Agent, $this->owner));
        };
        $this->says('where is my order?');

        $this->assertSame(['I am on it!'], $this->received(), 'the stale bot message was never sent');
        $this->assertSame('human', $this->conversation()->handler);
        $this->inTenant(fn () => $this->assertSame(0, Message::query()->where('origin', 'automation')->count()));
    }

    public function test_a_reply_from_the_whatsapp_business_app_switches_to_human(): void
    {
        $this->liveFlow();
        $this->says('hi');

        $this->postWebhook($this->webhookBody('smb_message_echoes', [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'],
            'message_echoes' => [['from' => '971585496310', 'to' => '971501234567', 'id' => 'wamid.ECHO1', 'timestamp' => (string) (now()->timestamp + 1), 'type' => 'text', 'text' => ['body' => 'Replying from my phone']]],
        ]))->assertOk();

        $this->assertSame('human', $this->conversation()->handler);
        $this->assertSame('handed_off', $this->botSession()->status);
    }

    public function test_reminder_then_abandonment_keep_the_conversation_open_and_a_quick_return_resumes(): void
    {
        $this->liveFlow();
        $this->says('hi');

        $this->travel(13)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();
        $this->assertSame('Are you still there? Just tap one of the options above to continue 🙂', last($this->received()));

        $this->travel(45)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();
        $session = $this->botSession();
        $this->assertSame('abandoned', $session->status);
        $this->assertSame('welcome', $session->ended_node, 'the step the customer left at is recorded');
        $conversation = $this->conversation();
        $this->assertSame('open', $conversation->status->value);
        $this->assertNull($conversation->handler);
        $this->assertSame('abandoned', $conversation->bot_state);
        $this->actingAsMember($this->owner);
        $this->getJson('/api/v1/conversations?view=abandoned')->assertJsonCount(1, 'data')->assertJsonPath('data.0.bot.node_title', 'Welcome menu');

        // Back within the hour: the tap answers the menu they left.
        $this->taps('bot:welcome:hours', 'Opening hours');
        $this->assertSame('We are open Sunday to Thursday, 9:00 to 18:00.', $this->received()[count($this->sent) - 2]);
        $this->assertSame($session->id, $this->botSession()->id, 'the same session continues');
        $this->assertSame('active', $this->botSession()->status);
    }

    public function test_a_customer_returning_after_the_resume_time_starts_from_the_main_menu(): void
    {
        $this->liveFlow();
        $this->says('hi');
        $this->travel(46)->minutes();
        $this->artisan('engage:chatbots:tick');
        $this->travel(2)->hours();

        $this->says('hello again');
        $this->assertSame("Hi Sara 👋 Welcome to {$this->tenant->name}! How can we help you today?", last($this->received()));
        $this->inTenant(fn () => $this->assertSame(2, ChatbotSession::query()->count()));
    }

    public function test_end_block_actions_run_and_close_the_conversation(): void
    {
        $graph = ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'ask']]],
            ['id' => 'ask', 'type' => 'buttons', 'config' => ['text' => 'Done?', 'buttons' => [['id' => 'yes', 'title' => 'Yes'], ['id' => 'agent', 'title' => 'Agent']]],
                'outputs' => [['id' => 'yes', 'to' => 'end'], ['id' => 'agent', 'to' => 'handoff'], ['id' => 'fallback', 'to' => 'handoff']]],
            ['id' => 'handoff', 'type' => 'handoff', 'config' => ['text' => 'Connecting'], 'outputs' => []],
            ['id' => 'end', 'type' => 'end', 'config' => ['text' => 'Bye!', 'action' => 'close'], 'outputs' => []],
        ]];
        $this->liveFlow('blank', $graph);
        $this->says('hi');
        $this->says('yes');

        $this->assertSame('Bye!', last($this->received()));
        $conversation = $this->conversation();
        $this->assertSame('closed', $conversation->status->value);
        $this->assertSame('completed', $conversation->bot_state);
        $this->assertSame('completed', $this->botSession()->status);
    }

    public function test_questions_conditions_and_set_field_write_through_the_contact_path(): void
    {
        $this->inTenant(fn () => ContactField::query()->create(['key' => 'tier', 'label' => 'Tier', 'type' => 'text']));
        $graph = ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'email']]],
            ['id' => 'email', 'type' => 'question', 'config' => ['text' => 'Your email?', 'save_to' => 'email', 'validate' => 'email', 'error_text' => 'Please type a valid email.'],
                'outputs' => [['id' => 'next', 'to' => 'vip'], ['id' => 'fallback', 'to' => 'handoff']]],
            ['id' => 'vip', 'type' => 'condition', 'config' => ['rules' => [['field' => 'email', 'op' => 'contains', 'value' => '@vip.com']]], 'outputs' => [['id' => 'yes', 'to' => 'gold'], ['id' => 'no', 'to' => 'handoff']]],
            ['id' => 'gold', 'type' => 'setfield', 'config' => ['target' => 'field:tier', 'value' => 'Gold'], 'outputs' => [['id' => 'next', 'to' => 'tag']]],
            ['id' => 'tag', 'type' => 'tag', 'config' => ['add' => ['VIP']], 'outputs' => [['id' => 'next', 'to' => 'thanks']]],
            ['id' => 'thanks', 'type' => 'end', 'config' => ['text' => 'Welcome, {{contact.tier}} member {{contact.first_name}}!'], 'outputs' => []],
            ['id' => 'handoff', 'type' => 'handoff', 'config' => ['text' => 'A person will help'], 'outputs' => []],
        ]];
        $this->liveFlow('blank', $graph);
        $this->says('hi');
        $this->says('not an email');
        $this->assertSame('Please type a valid email.', $this->received()[1]);
        $this->says('sara@vip.com');

        $this->assertSame('Welcome, Gold member Sara!', last($this->received()));
        $contact = $this->inTenant(fn () => Contact::query()->sole());
        $this->assertSame('sara@vip.com', $contact->email);
        $this->assertSame('Gold', $contact->custom_fields['tier']);
        $this->assertSame(['VIP'], $contact->tags);
    }

    public function test_stop_ends_the_bot_and_is_never_answered_by_it(): void
    {
        $this->liveFlow();
        $this->says('hi');
        $this->says('STOP');

        $this->assertSame('stopped', $this->botSession()->status);
        $this->assertSame('opted_out', $this->botSession()->ended_reason);
        $this->assertNotContains("Sorry, I didn't catch that. Please choose one of the options.", $this->received());
    }

    public function test_the_same_message_delivered_twice_gets_one_answer(): void
    {
        $this->liveFlow();
        $value = $this->inboundValue(['id' => 'wamid.DUP', 'text' => ['body' => 'hi']], bsuid: null);
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();
        $value['messages'][0]['timestamp'] = (string) (now()->timestamp + 1);
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();

        $this->assertCount(1, $this->sent);
    }

    public function test_a_closed_window_stops_the_bot_instead_of_failing_silently(): void
    {
        $graph = ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'wait']]],
            ['id' => 'wait', 'type' => 'delay', 'config' => ['minutes' => 30], 'outputs' => [['id' => 'next', 'to' => 'follow']]],
            ['id' => 'follow', 'type' => 'message', 'config' => ['text' => 'Still interested?'], 'outputs' => [['id' => 'next', 'to' => 'handoff']]],
            ['id' => 'handoff', 'type' => 'handoff', 'config' => ['text' => 'A person will help'], 'outputs' => []],
        ]];
        $this->liveFlow('blank', $graph);
        $this->says('hi');
        $this->assertSame('waiting', $this->botSession()->status);

        $this->inTenant(fn () => Conversation::query()->update(['window_expires_at' => now()->subMinute()]));
        $this->travel(31)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();

        $this->assertSame([], $this->sent);
        $this->assertSame('stopped', $this->botSession()->status);
        $this->assertSame('window_closed', $this->botSession()->ended_reason);
    }

    public function test_the_publish_gate_blocks_dead_ends_orphans_and_flows_without_a_person(): void
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Draft', 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'menu']]],
            ['id' => 'menu', 'type' => 'buttons', 'title' => 'Menu', 'config' => ['text' => 'Pick', 'buttons' => [['id' => 'a', 'title' => 'A']]], 'outputs' => [['id' => 'a', 'to' => 'bye']]],
            ['id' => 'bye', 'type' => 'end', 'config' => ['text' => 'Bye'], 'outputs' => []],
            ['id' => 'lost', 'type' => 'message', 'title' => 'Lost', 'config' => ['text' => 'x'], 'outputs' => []],
        ]]])->assertOk();

        $issues = collect($this->postJson("/api/v1/chatbots/{$id}/publish")->assertStatus(422)->json('error.details.issues'))->pluck('message')->implode("\n");
        $this->assertStringContainsString('“Lost”: the “Next” branch goes nowhere.', $issues);
        $this->assertStringContainsString('“Lost” can never be reached', $issues);
        $this->assertStringContainsString('Customers can never reach a person.', $issues);
        // "No match" is optional: a menu without it asks again, then hands over — not an error.
        $this->assertStringNotContainsString('No match', $issues);

        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertStatus(422); // never published
    }

    public function test_one_live_bot_per_number_and_the_plan_limit(): void
    {
        $first = $this->liveFlow();
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Second', 'phone_number_id' => $this->number->id, 'template' => 'lead'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertStatus(409)->assertJsonPath('error.details.active_flow.name', 'Main bot');
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true, 'replace' => true])->assertOk();
        $this->assertFalse($this->inTenant(fn () => $first->refresh()->is_active));

        // Free has no chatbots.
        $this->subscribe($this->tenant, 'free');
        $this->postJson('/api/v1/chatbots', ['name' => 'Third'])->assertStatus(403);
        $this->getJson('/api/v1/chatbots')->assertOk()->assertJsonPath('meta.enabled', false);
    }

    public function test_the_test_chat_runs_the_draft_without_sending_or_saving(): void
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Draft', 'template' => 'menu'])->assertCreated()->json('data.id');

        $start = $this->postJson("/api/v1/chatbots/{$id}/test")->assertOk();
        $this->assertSame("Hi Test 👋 Welcome to {$this->tenant->name}! How can we help you today?", $start->json('data.transcript.0.message.body'));
        $this->assertSame('welcome', $start->json('data.state.node_id'));

        $next = $this->postJson("/api/v1/chatbots/{$id}/test", ['test_id' => $start->json('data.test_id'), 'payload' => 'bot:welcome:person'])->assertOk();
        $this->assertSame('handed_off', $next->json('data.state.status'));
        $this->assertStringContainsString('Handed off to a person', collect($next->json('data.transcript'))->where('kind', 'note')->pluck('text')->implode(' '));
        $this->assertSame([], $this->sent);
    }

    public function test_api_secrets_are_write_only(): void
    {
        $this->actingAsMember($this->owner);
        $this->putJson('/api/v1/chatbots/secrets', ['name' => 'WOO_KEY', 'value' => 'ck_1234567890abcdef'])->assertOk()
            ->assertJsonPath('data.0.name', 'WOO_KEY')->assertJsonPath('data.0.hint', '…cdef')->assertJsonMissing(['value' => 'ck_1234567890abcdef']);
        $this->getJson('/api/v1/chatbots/options')->assertOk()->assertJsonPath('data.secrets.0', 'WOO_KEY');
        $this->deleteJson('/api/v1/chatbots/secrets/WOO_KEY')->assertNoContent();
    }
}
