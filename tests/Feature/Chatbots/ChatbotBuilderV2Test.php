<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Application\Messaging\ManageContacts;
use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Engine\Expression;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Chatbots\Services\ChatbotWorkspace;
use App\Domain\Chatbots\Services\FlowTemplates;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Chatbot builder v2: templates, optional "No match", several flows per number, subflows, timers, triggers, approvals. */
final class ChatbotBuilderV2Test extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<string> wamids of the messages sent, in order */
    private array $wamids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['name' => 'Sweet Crumbs']);
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
                $this->wamids[] = $id = 'wamid.OUT'.bin2hex(random_bytes(5));

                return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => $id]]]);
            },
            'script.google.com/*' => Http::response('', 302, ['Location' => 'https://script.googleusercontent.com/x']),
        ]);
        $this->actingAsMember($this->owner);
    }

    /** @template T @param callable(): T $fn @return T */
    private function inTenant(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    /** @param array<string, mixed> $message */
    private function customer(array $message = [], string $waId = '971501234567'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue($message, waId: $waId, bsuid: null)))->assertOk();
    }

    private function says(string $text, string $waId = '971501234567'): void
    {
        $this->customer(['text' => ['body' => $text]], $waId);
    }

    private function taps(string $id, string $title): void
    {
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]]);
    }

    /** @return list<string> */
    private function received(): array
    {
        return array_map(fn (array $m) => (string) ($m['text']['body'] ?? $m['interactive']['body']['text'] ?? $m['image']['caption'] ?? $m['template']['name'] ?? $m['type']), $this->sent);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $settings
     */
    private function flow(string $name, array $nodes, array $settings = [], bool $live = true): string
    {
        $id = $this->postJson('/api/v1/chatbots', ['name' => $name, 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", array_filter(['draft' => ['nodes' => $nodes], 'settings' => $settings ?: null]))->assertOk();
        if ($live) {
            $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
            $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, ?string>  $to
     * @return array<string, mixed>
     */
    private static function node(string $id, string $type, array $config, array $to = [], ?string $title = null): array
    {
        return ['id' => $id, 'type' => $type, 'title' => $title ?? ucfirst($id), 'config' => $config, 'outputs' => array_map(fn ($o, $t) => ['id' => $o, 'to' => $t], array_keys($to), $to)];
    }

    private function botSession(): ChatbotSession
    {
        return $this->inTenant(fn () => ChatbotSession::query()->latest('created_at')->firstOrFail());
    }

    // ── Templates ────────────────────────────────────────────────────────────────────────────

    public function test_every_template_creates_a_flow_that_passes_the_publish_gate_and_fills_business_variables(): void
    {
        $catalog = $this->getJson('/api/v1/chatbots/templates')->assertOk()->json('data');
        $this->assertSame(FlowTemplates::KEYS, array_column($catalog, 'key'));

        foreach (FlowTemplates::KEYS as $key) {
            $flow = $this->postJson('/api/v1/chatbots', ['name' => $key, 'template' => $key, 'config' => ['store_url' => 'https://sweetcrumbs.ae', 'booking_url' => 'https://rent.example.ae']])->assertCreated();
            $this->assertSame([], array_values(array_filter($flow->json('data.issues'), fn ($i) => $i['level'] === 'error')), "template {$key}");
            $this->postJson('/api/v1/chatbots/'.$flow->json('data.id').'/publish')->assertOk();
        }

        $vars = $this->inTenant(fn () => ChatbotWorkspace::variables($this->tenant->refresh()));
        $this->assertSame('https://sweetcrumbs.ae', $vars['store_url']);
        $this->assertSame('14:00', $vars['cutoff_time']);
        $this->assertSame('Sweet Crumbs', $vars['name']);
    }

    public function test_the_bakery_template_shows_product_cards_and_routes_custom_cakes_to_a_baker(): void
    {
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Cakes', 'phone_number_id' => $this->number->id, 'template' => 'bakery', 'config' => ['store_url' => 'https://sweetcrumbs.ae']])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();

        $this->says('hi');
        $this->assertSame('Hi 🎂 Welcome to Sweet Crumbs — fresh cakes, same-day delivery.', $this->received()[0]);
        $this->taps('bot:welcome:order', 'Order a Cake');
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'bot:categories:cat1', 'title' => 'Birthday']]]);
        $cards = array_values(array_filter($this->sent, fn ($m) => ($m['interactive']['type'] ?? null) === 'cta_url'));
        $this->assertGreaterThanOrEqual(3, count($cards));
        $this->assertSame('https://sweetcrumbs.ae/product/chocolate-fudge-cake', $cards[0]['interactive']['action']['parameters']['url']);
        $this->assertSame('image', $cards[0]['interactive']['header']['type']);
        $this->assertSame('How would you like to order?', last($this->received()));

        // Custom cake: optional photo skipped, then occasion / date / budget → tagged lead, handed to a baker with a note.
        $this->says('menu');
        $this->taps('bot:welcome:custom', 'Custom Cake');
        $this->taps('bot:photo:skip', 'Skip');
        $this->says('Birthday');
        $this->says(now()->addDays(3)->format('d/m/Y'));
        $this->says('350');
        $this->assertSame('A baker will confirm design & price shortly 👩‍🍳', last($this->received()));
        $contact = $this->inTenant(fn () => Contact::query()->sole());
        $this->assertContains('Custom Cake Lead', $contact->tags);
        $note = $this->inTenant(fn () => ConversationNote::query()->latest('created_at')->firstOrFail());
        $this->assertSame('Cakes', $note->author_name);
        $this->assertStringContainsString('Occasion: Birthday', $note->body);
        $this->assertStringContainsString('Budget: 350', $note->body);
        $this->assertStringContainsString('Welcome: Custom Cake', $note->body); // a menu choice is labelled with the menu's name
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.bot.bot_name', 'Cakes')->assertJsonPath('data.0.bot.state', 'handed_off');
        $this->getJson('/api/v1/conversations/'.$this->inTenant(fn () => Conversation::query()->sole()->id).'/notes')
            ->assertJsonPath('data.0.author', 'Cakes')->assertJsonPath('data.0.by_bot', true);
    }

    // ── "No match" ───────────────────────────────────────────────────────────────────────────

    public function test_no_match_is_optional_handoff_by_default_branch_when_asked_and_silent_when_off(): void
    {
        $menu = fn (string $mode, array $extra = []) => self::node('menu', 'buttons', ['text' => 'Pick one', 'no_match' => $mode, 'retries' => 1, 'buttons' => [['id' => 'a', 'title' => 'Apples'], ['id' => 'p', 'title' => 'Agent']]],
            ['a' => 'bye', 'p' => 'person'] + $extra);
        $nodes = fn (array $menuNode) => [
            self::node('start', 'start', ['any_message' => true], ['next' => 'menu']),
            $menuNode,
            self::node('other', 'message', ['text' => 'Here is the help menu'], ['next' => 'bye']),
            self::node('bye', 'end', ['text' => 'Bye']),
            self::node('person', 'handoff', ['text' => 'A person will help you']),
        ];

        // "branch" without a target is an error on that exact branch.
        $id = $this->flow('Branch', $nodes($menu('branch')), live: false);
        $issue = collect($this->getJson("/api/v1/chatbots/{$id}")->json('data.issues'))->firstWhere('code', 'unconnected');
        $this->assertSame(['menu', 'fallback'], [$issue['node_id'], $issue['output_id']]);
        $this->assertStringContainsString('No match', $issue['message']);
        $this->assertNotEmpty($issue['fix']);

        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => $nodes($menu('branch', ['fallback' => 'other']))]])->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();
        $this->says('hi');
        $this->says('pears');
        $this->says('pears again');
        $this->assertSame(['Pick one', "Sorry, I didn't catch that. Please choose one of the options.", 'Pick one', 'Here is the help menu', 'Bye'], $this->received());

        // "off": no reply at all to unknown answers, and only a warning at publish time.
        $this->sent = [];
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => array_values(array_filter($nodes($menu('off')), fn ($n) => $n['id'] !== 'other'))]])->assertOk();
        $warning = collect($this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk()->json('data.issues'))->firstWhere('code', 'no_match_off');
        $this->assertSame('warning', $warning['level']);
        $this->says('hello', '971509999999');
        $this->says('pears', '971509999999');
        $this->says('pears', '971509999999');
        $this->assertSame(['Pick one'], $this->received());
    }

    public function test_voice_notes_and_emoji_get_a_hint_and_do_not_count_as_wrong_answers(): void
    {
        $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'menu']),
            self::node('menu', 'buttons', ['text' => 'Pick one', 'retries' => 0, 'buttons' => [['id' => 'a', 'title' => 'Apples']]], ['a' => 'bye']),
            self::node('bye', 'end', ['text' => 'Bye']),
            self::node('person', 'handoff', ['text' => 'A person will help you']),
        ]);
        $this->says('hi');
        $this->customer(['type' => 'location', 'location' => ['latitude' => 25.2, 'longitude' => 55.3]]);
        $this->says('👍🙂');
        $this->assertSame('running', $this->inTenant(fn () => Conversation::query()->sole()->bot_state));
        $this->assertSame(0, $this->botSession()->retry_count);
        $this->assertStringContainsString('I can only read typed answers', $this->received()[1]);
        $this->says('apples');
        $this->assertSame('Bye', last($this->received()));
    }

    // ── Several flows per number ─────────────────────────────────────────────────────────────

    public function test_keyword_greeting_and_default_flows_share_a_number_by_precedence(): void
    {
        $simple = fn (string $text, array $start) => [
            self::node('start', 'start', $start, ['next' => 'msg']),
            self::node('msg', 'message', ['text' => $text], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => null]),
        ];
        $this->flow('Default', $simple('default flow', ['any_message' => true]));
        $this->flow('Greeting', $simple('greeting flow', ['any_message' => false, 'greeting' => true]));
        $this->flow('Promo', $simple('promo flow', ['any_message' => false, 'keywords' => ['promo'], 'keyword_match' => 'contains', 'reentry' => 'once']));
        // A second default flow on the same number conflicts.
        $dup = $this->flow('Default 2', $simple('x', ['any_message' => true]), live: false);
        $this->postJson("/api/v1/chatbots/{$dup}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$dup}/activate", ['active' => true])->assertStatus(409)->assertJsonPath('error.details.active_flow.name', 'Default');

        $this->says('hello there', '971500000001');
        $this->assertSame('greeting flow', last($this->received()));

        $this->says('any promo today?', '971500000002');
        $this->assertSame('promo flow', last($this->received()));

        // A person took over each conversation (handoff); after "hand back" the next message chooses again.
        $this->inTenant(fn () => Conversation::query()->update(['handler' => null]));
        $this->says('promo again', '971500000002'); // "once": the promo flow is skipped, the default flow answers
        $this->assertSame('default flow', last($this->received()));
    }

    // ── Subflows, Go to, back ────────────────────────────────────────────────────────────────

    public function test_subflows_run_and_return_and_go_to_jumps(): void
    {
        $sub = $this->postJson('/api/v1/chatbots', ['name' => 'Shared FAQ', 'kind' => 'subflow'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$sub}", ['draft' => ['nodes' => [
            self::node('start', 'start', ['any_message' => false], ['next' => 'q']),
            self::node('q', 'buttons', ['text' => 'FAQ: which topic?', 'buttons' => [['id' => 'd', 'title' => 'Delivery']]], ['d' => 'a']),
            self::node('a', 'message', ['text' => 'We deliver everywhere'], ['next' => 'done']),
            self::node('done', 'end', ['text' => null]),
        ]]])->assertOk();
        $issues = $this->postJson("/api/v1/chatbots/{$sub}/publish")->assertOk()->json('data.issues');
        $this->assertSame([], array_values(array_filter($issues, fn ($i) => $i['level'] === 'error')), 'subflows need no trigger and no handoff');
        $this->postJson("/api/v1/chatbots/{$sub}/activate", ['active' => true])->assertStatus(422);

        $this->flow('Main', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'faq']),
            self::node('faq', 'subflow', ['flow_id' => $sub], ['next' => 'after']),
            self::node('after', 'message', ['text' => 'Back in the main flow'], ['next' => 'jump']),
            self::node('jump', 'goto', ['target' => 'person']),
            self::node('person', 'handoff', ['text' => 'A person will help you']),
        ]);
        $this->says('hi');
        $this->assertSame('FAQ: which topic?', last($this->received()));
        $this->taps('bot:q:d', 'Delivery');
        $this->assertSame(['FAQ: which topic?', 'We deliver everywhere', 'Back in the main flow', 'A person will help you'], $this->received());
    }

    public function test_back_asks_the_previous_question_again(): void
    {
        $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'one']),
            self::node('one', 'question', ['text' => 'Your name?', 'save_to' => 'var:n', 'validate' => 'text'], ['next' => 'two']),
            self::node('two', 'question', ['text' => 'Your city?', 'save_to' => 'var:c', 'validate' => 'text'], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => 'Thanks {{var.n}} from {{var.c}}']),
        ]);
        $this->says('hi');
        $this->says('Sara');
        $this->says('back');
        $this->assertSame('Your name?', last($this->received()));
        $this->says('Sara Ahmed');
        $this->says('Dubai');
        $this->assertSame('Thanks Sara Ahmed from Dubai', last($this->received()));
    }

    // ── Timers ───────────────────────────────────────────────────────────────────────────────

    public function test_the_no_reply_branch_runs_after_the_steps_timer(): void
    {
        $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'ask']),
            self::node('ask', 'question', ['text' => 'Your order number?', 'save_to' => 'var:o', 'timeout_minutes' => 5], ['next' => 'person', 'timeout' => 'nudge']),
            self::node('nudge', 'message', ['text' => 'No worries — a person will follow up.'], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => null]),
        ], ['abandon_minutes' => 30]);
        $this->says('hi');
        $this->assertNotNull($this->botSession()->timeout_at);

        $this->travel(4)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();
        $this->assertSame('Your order number?', last($this->received()));

        $this->travel(2)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();
        $this->assertSame('No worries — a person will follow up.', last($this->received()));
        $this->assertSame('handed_off', $this->botSession()->status);
    }

    public function test_a_template_with_a_not_delivered_branch_follows_the_delivery_report(): void
    {
        $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'tpl']),
            self::node('tpl', 'template', ['name' => 'order_update', 'language' => 'en', 'body' => ['{{contact.first_name}}'], 'on_failure' => true], ['next' => 'ok', 'failed' => 'fail']),
            self::node('ok', 'end', ['text' => null]),
            self::node('fail', 'handoff', ['text' => null]),
        ]);
        $this->says('hi');
        $this->assertSame('waiting', $this->botSession()->status);

        $this->postWebhook($this->webhookBody('messages', [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'],
            'statuses' => [['id' => $this->wamids[0], 'status' => 'failed', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '971501234567',
                'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]],
        ]))->assertOk();

        $this->assertSame('handed_off', $this->botSession()->status);
    }

    public function test_scheduled_switch_on_and_off(): void
    {
        $id = $this->flow('Later', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => 'Hi']),
        ], live: false);
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->patchJson("/api/v1/chatbots/{$id}", ['activate_at' => now()->addMinutes(10)->toIso8601String(), 'deactivate_at' => now()->addHour()->toIso8601String()])->assertOk();

        $this->travel(11)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();
        $this->assertTrue($this->inTenant(fn () => ChatbotFlow::query()->findOrFail($id)->is_active));

        $this->travel(1)->hours();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();
        $this->assertFalse($this->inTenant(fn () => ChatbotFlow::query()->findOrFail($id)->is_active));
    }

    // ── Logic blocks ─────────────────────────────────────────────────────────────────────────

    public function test_calculate_condition_and_sheets_blocks(): void
    {
        $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'size']),
            self::node('size', 'list', ['text' => 'Size?', 'sections' => [['title' => 'Sizes', 'rows' => [['id' => 's', 'title' => 'Studio'], ['id' => 'v', 'title' => 'Villa']]]]], ['s' => 'calc', 'v' => 'calc']),
            self::node('calc', 'calculate', ['expression' => "pick({{var.size}}, 'Studio', 150, 'Villa', 550) * 1.8", 'target' => 'var:price', 'decimals' => 0], ['next' => 'big']),
            self::node('big', 'condition', ['rules' => [['field' => 'var:price', 'op' => 'gte', 'value' => '500']]], ['yes' => 'pricey', 'no' => 'cheap']),
            self::node('pricey', 'message', ['text' => 'Big job: AED {{var.price}}'], ['next' => 'sheet']),
            self::node('cheap', 'message', ['text' => 'Small job: AED {{var.price}}'], ['next' => 'sheet']),
            self::node('sheet', 'sheets', ['webapp_url' => 'https://script.google.com/macros/s/abc/exec', 'columns' => [['name' => 'Price', 'value' => '{{var.price}}']]], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => null]),
        ]);
        $this->says('hi');
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'bot:size:v', 'title' => 'Villa']]]);
        $this->assertSame('Big job: AED 990', last($this->received()));
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://script.google.com/') && $r['row']['Price'] === '990');

        $this->assertSame(270.0, Expression::evaluate("pick('Studio', 'Studio', 150) * 1.8", fn () => null));
        $this->assertSame(3.0, Expression::evaluate("days_between('2026-10-15', '2026-10-18')", fn () => null));
        $this->assertFalse(Expression::valid('1 +'));
        $this->assertFalse(Expression::valid('system(1)'));
    }

    // ── Triggers without a customer message ──────────────────────────────────────────────────

    public function test_a_tag_added_and_an_incoming_webhook_start_flows(): void
    {
        $this->says('hi'); // the customer wrote earlier, so the 24-hour window is open (no live flow yet)
        $this->flow('VIP welcome', [
            self::node('start', 'start', ['any_message' => false, 'tags_added' => ['VIP']], ['next' => 'msg']),
            self::node('msg', 'message', ['text' => 'Welcome to VIP, {{contact.first_name}}!'], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => null]),
        ]);
        $this->inTenant(fn () => app(ManageContacts::class)->update(Contact::query()->sole(), ['tags' => ['VIP']], partial: true));
        $this->assertSame('Welcome to VIP, Sara!', last($this->received()));

        $hook = $this->flow('Hook', [
            self::node('start', 'start', ['any_message' => false, 'webhook' => true], ['next' => 'tpl']),
            self::node('tpl', 'template', ['name' => 'order_update', 'language' => 'en', 'body' => ['{{var.order}}']], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => null]),
        ]);
        $url = $this->postJson("/api/v1/chatbots/{$hook}/webhook", ['enabled' => true])->assertOk()->json('data.webhook_url');
        $this->assertNotNull($url);
        $this->postJson((string) parse_url($url, PHP_URL_PATH), ['phone' => '+971 50 777 8888', 'name' => 'Omar', 'vars' => ['order' => '1042']])
            ->assertStatus(202)->assertJsonPath('data.result', 'started');
        $last = last($this->sent);
        $this->assertSame('template', $last['type']);
        $this->assertSame('971507778888', $last['to']);
        $this->postJson('/api/chatbots/hooks/'.str_repeat('a', 40), ['phone' => '+971507778888'])->assertNotFound();
    }

    // ── Agency: roles, approvals, comments, history, export ─────────────────────────────────

    public function test_agency_mode_needs_an_approval_of_the_exact_draft_and_viewers_cannot_publish(): void
    {
        $id = $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => 'Hi']),
        ], live: false);
        $this->putJson('/api/v1/chatbots/workspace', ['approvals' => true])->assertOk()->assertJsonPath('data.approvals', true);

        $issue = collect($this->postJson("/api/v1/chatbots/{$id}/publish")->assertStatus(422)->json('error.details.issues'))->firstWhere('code', 'approval');
        $this->assertNotNull($issue);
        $this->postJson("/api/v1/chatbots/{$id}/review", ['action' => 'request', 'note' => 'Ready'])->assertOk()->assertJsonPath('data.review.status', 'requested');
        $this->postJson("/api/v1/chatbots/{$id}/review", ['action' => 'approve'])->assertOk()->assertJsonPath('data.review.status', 'approved');

        // An edit after the approval needs a new one.
        $this->patchJson("/api/v1/chatbots/{$id}", ['name' => 'Renamed', 'draft' => ['nodes' => [
            self::node('start', 'start', ['any_message' => true], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => 'Hello']),
        ]]])->assertOk()->assertJsonPath('data.review.current', false);
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertStatus(422);
        $this->postJson("/api/v1/chatbots/{$id}/review", ['action' => 'approve'])->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();

        $viewer = $this->addMember($this->tenant, SystemRole::Viewer);
        $this->actingAsMember($viewer);
        $this->getJson("/api/v1/chatbots/{$id}")->assertOk()->assertJsonPath('data.can.publish', false);
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertForbidden();
        $this->patchJson("/api/v1/chatbots/{$id}", ['name' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/chatbots/{$id}/comments", ['node_id' => 'person', 'body' => 'Say good morning?'])->assertOk()->assertJsonPath('data.0.body', 'Say good morning?');
    }

    public function test_comments_history_export_import_folders_archive_and_snippets(): void
    {
        $id = $this->flow('Support', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => 'Hi']),
        ]);
        $comment = $this->postJson("/api/v1/chatbots/{$id}/comments", ['node_id' => 'person', 'body' => 'Friendlier?'])->assertOk()->json('data.0.id');
        $this->patchJson("/api/v1/chatbots/{$id}/comments/{$comment}", ['resolved' => true])->assertOk()->assertJsonPath('data.0.resolved_by', $this->owner->user->name);
        $actions = collect($this->getJson("/api/v1/chatbots/{$id}/history")->assertOk()->json('data'))->pluck('action');
        $this->assertContains('chatbot.published', $actions);
        $this->assertContains('chatbot.activated', $actions);

        $export = $this->getJson("/api/v1/chatbots/{$id}/export")->assertOk()->json();
        $copy = $this->postJson('/api/v1/chatbots/import', $export)->assertCreated();
        $this->assertSame('Support', $copy->json('data.name'));
        $this->assertSame('draft', $copy->json('data.status'));
        $this->postJson('/api/v1/chatbots/import', ['format' => 'other', 'flow' => []])->assertUnprocessable();

        $this->patchJson("/api/v1/chatbots/{$id}", ['folder' => 'Support bots'])->assertOk();
        $this->getJson('/api/v1/chatbots?folder=Support+bots')->assertJsonCount(1, 'data')->assertJsonPath('meta.folders.0', 'Support bots');
        $this->postJson("/api/v1/chatbots/{$id}/archive", ['archived' => true])->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/chatbots?status=archived')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/chatbots?q=supp')->assertJsonCount(1, 'data'); // the import; the archived one is hidden

        $this->putJson('/api/v1/chatbots/snippets', ['name' => 'Hours', 'body' => 'Open 9–6'])->assertOk()->assertJsonPath('data.0.name', 'Hours');
        $this->putJson('/api/v1/chatbots/snippets', ['name' => 'Hours', 'body' => 'dup'])->assertUnprocessable();
        $this->getJson('/api/v1/chatbots/options')->assertJsonPath('data.snippets.0.body', 'Open 9–6');
    }

    public function test_analytics_report_completion_time_handoffs_and_drop_off(): void
    {
        $id = $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'menu']),
            self::node('menu', 'buttons', ['text' => 'Pick one', 'buttons' => [['id' => 'a', 'title' => 'Agent']]], ['a' => 'person']),
            self::node('person', 'handoff', ['text' => 'Hi']),
        ], ['abandon_minutes' => 30]);
        $this->says('hi');
        $this->taps('bot:menu:a', 'Agent');
        $this->says('hi', '971500000012');
        $this->travel(31)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();

        $data = $this->getJson("/api/v1/chatbots/{$id}/analytics")->assertOk()->json('data');
        $this->assertSame(2, $data['sessions']['started']);
        $this->assertSame(50.0, (float) $data['sessions']['handoff_rate']);
        $this->assertNotNull($data['sessions']['avg_seconds']);
        $this->assertSame('menu', $data['dropoff'][0]['node_id']);
        $this->assertSame(1, $data['dropoff'][0]['abandoned']);
    }

    public function test_live_flows_with_a_deleted_field_or_rejected_template_need_attention(): void
    {
        $field = $this->inTenant(fn () => ContactField::query()->create(['key' => 'tier', 'label' => 'Tier', 'type' => 'text']));
        $this->flow('Bot', [
            self::node('start', 'start', ['any_message' => true], ['next' => 'set']),
            self::node('set', 'setfield', ['target' => 'field:tier', 'value' => 'Gold'], ['next' => 'person']),
            self::node('person', 'handoff', ['text' => 'Hi']),
        ]);
        $this->getJson('/api/v1/chatbots')->assertOk()->assertJsonPath('data.0.attention', []);

        $this->inTenant(fn () => $field->delete());
        $attention = $this->getJson('/api/v1/chatbots')->assertOk()->json('data.0.attention');
        $this->assertCount(1, $attention);
        $this->assertStringContainsString('no longer exists', $attention[0]);
    }
}
