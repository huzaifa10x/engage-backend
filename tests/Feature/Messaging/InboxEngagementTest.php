<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\BusinessHours;
use App\Domain\Messaging\Services\ConversationTracker;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * WhatsApp Engagement Plan v4, steps A and B: who each chat is waiting on, response times in
 * business hours, the one scheduler (snooze returns), opening hours with weekends, holidays and
 * per-number hours, and the after-hours flow that answers once per closed period.
 */
final class InboxEngagementTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const SEND = 'graph.facebook.com/v26.0/106540352242922/messages*';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        // Thursday 5 November 2026, 10:00 in Dubai: open (Monday–Friday 09:00–18:00).
        $this->travelTo(Carbon::parse('2026-11-05 10:00', 'Asia/Dubai'));
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        Http::fake([self::SEND => function ($r) {
            $this->sent[] = $r->data();

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]]);
        }]);
        $this->actingAsMember($this->owner);
        $week = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $week[$d] = ['open' => ! in_array($d, ['sat', 'sun'], true), 'from' => '09:00', 'to' => '18:00'];
        }
        $this->putJson('/api/v1/tenant/inbox-settings', ['business_hours' => ['enabled' => true, 'days' => $week], 'holidays' => [['date' => '2026-12-02', 'name' => 'National Day']]])->assertOk();
    }

    private function customer(string $text, string $waId = '971501234567', array $extra = []): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text], 'timestamp' => (string) now()->timestamp] + $extra, waId: $waId, bsuid: null)))->assertOk();
    }

    private function conversation(string $waId = '971501234567'): Conversation
    {
        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($c) => $c->where('wa_id', $waId))->sole());
    }

    public function test_a_customer_message_needs_a_reply_until_a_person_answers_and_the_response_time_counts_business_hours(): void
    {
        $this->customer('Do you have this in blue?');
        $c = $this->conversation();
        $chat = $this->getJson("/api/v1/conversations/{$c->id}")->assertOk()->json('data');
        $this->assertSame('needs_reply', $chat['state']);
        $this->assertFalse($chat['after_hours']);
        $this->assertSame(1, $this->getJson('/api/v1/conversations/counts')->json('data.needs_reply'));
        $this->assertCount(1, $this->getJson('/api/v1/conversations?state=needs_reply')->json('data'));

        $this->travel(14)->minutes();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Yes, we do!'])->assertSuccessful();

        $this->assertSame('waiting', $this->getJson("/api/v1/conversations/{$c->id}")->json('data.state'));
        $this->assertSame(0, $this->getJson('/api/v1/conversations/counts')->json('data.needs_reply'));
        $row = $this->tenantContext()->run($this->tenant, fn () => DB::table('inbox_response_times')->where('kind', 'first_response')->sole());
        $this->assertSame(14 * 60, (int) $row->business_seconds);
        $this->assertSame($this->owner->id, $row->membership_id);

        $stats = $this->getJson('/api/v1/analytics/response-times')->assertOk()->json('data');
        $this->assertSame(1, $stats['first_response']['total']);
        $this->assertSame(840, $stats['first_response']['median_seconds']);
        $this->assertSame(0, $stats['first_response']['within_target'], 'target 10 minutes');
        $this->assertSame(840, $stats['agents'][0]['median_seconds']);
    }

    public function test_a_message_at_night_arrived_after_hours_and_its_wait_counts_only_opening_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 22:00', 'Asia/Dubai'));       // Thursday night
        $this->customer('Are you open tomorrow?');
        $c = $this->conversation();
        $this->assertTrue($this->getJson("/api/v1/conversations/{$c->id}")->json('data.after_hours'));
        $this->assertSame(1, $this->getJson('/api/v1/conversations/counts')->json('data.after_hours'));
        $this->assertCount(1, $this->getJson('/api/v1/conversations?after_hours=1')->json('data'));

        $this->travelTo(Carbon::parse('2026-11-06 09:05', 'Asia/Dubai'));       // Friday 09:05
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Good morning! Yes.'])->assertSuccessful();
        $row = $this->tenantContext()->run($this->tenant, fn () => DB::table('inbox_response_times')->where('kind', 'first_response')->sole());
        $this->assertSame(5 * 60, (int) $row->business_seconds, 'only the five minutes after opening count');
        $this->assertTrue((bool) $row->after_hours);
        $this->assertFalse($this->getJson("/api/v1/conversations/{$c->id}")->json('data.after_hours'));
    }

    public function test_needs_reply_lists_ad_leads_first_then_the_longest_waiting(): void
    {
        $this->customer('First', '971500000001');
        $this->travel(5)->minutes();
        $this->customer('From the ad', '971500000002', ['referral' => ['source_id' => '120208000000000001', 'source_type' => 'ad']]);
        $this->travel(5)->minutes();
        $this->customer('Third', '971500000003');

        $phones = array_map(fn ($c) => $c['contact']['wa_id'] ?? null, $this->getJson('/api/v1/conversations?state=needs_reply')->json('data'));
        $this->assertSame(['971500000002', '971500000001', '971500000003'], $phones);
    }

    public function test_closing_records_the_resolution_and_a_new_message_reopens_it_as_needs_reply(): void
    {
        $this->customer('Hello');
        $c = $this->conversation();
        $this->travel(30)->minutes();
        $this->patchJson("/api/v1/conversations/{$c->id}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.state', 'closed');
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame(1800, (int) DB::table('inbox_response_times')->where('kind', 'resolution')->value('business_seconds')));

        $this->travel(1)->hours();
        $this->customer('One more question');
        $this->assertSame('needs_reply', $this->getJson("/api/v1/conversations/{$c->id}")->json('data.state'));
    }

    public function test_a_snoozed_chat_comes_back_as_needs_reply_through_the_scheduler(): void
    {
        $this->customer('Hi');
        $c = $this->conversation();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Let me check.'])->assertSuccessful();
        $this->postJson("/api/v1/conversations/{$c->id}/snooze", ['until' => now()->addHours(2)->toIso8601String()])->assertOk()->assertJsonPath('data.state', 'snoozed');
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame(1, DB::table('scheduled_actions')->where('kind', 'snooze_return')->where('status', 'pending')->count()));

        $this->travel(121)->minutes();
        Artisan::call('engage:inbox:wake-snoozed');
        $this->assertSame('needs_reply', $this->getJson("/api/v1/conversations/{$c->id}")->json('data.state'));
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame('done', DB::table('scheduled_actions')->where('kind', 'snooze_return')->value('status')));
    }

    public function test_the_bot_replying_does_not_count_as_the_team_replying(): void
    {
        $this->customer('Hi');
        $c = $this->conversation();
        // A chatbot message (origin automation) keeps the chat in Needs reply.
        $this->tenantContext()->run($this->tenant, function () use ($c) {
            $m = Message::query()->create([
                'conversation_id' => $c->id, 'phone_number_id' => $c->phone_number_id, 'contact_id' => $c->contact_id,
                'direction' => 'outbound', 'origin' => 'automation', 'type' => 'text', 'status' => 'sent', 'body' => 'Thanks! A person will reply soon.',
                'meta_timestamp' => now()->addSecond(),
            ]);
            app(ConversationTracker::class)->apply($m);
        });
        $this->assertSame('needs_reply', $this->getJson("/api/v1/conversations/{$c->id}")->json('data.state'));
    }

    public function test_hours_with_weekends_holidays_and_per_number_hours(): void
    {
        $t = $this->tenantContext()->bypass(fn () => $this->tenant->refresh());
        $dubai = fn (string $s) => Carbon::parse($s, 'Asia/Dubai');
        $this->assertTrue(BusinessHours::isOpen($t, null, $dubai('2026-11-05 10:00')));
        $this->assertFalse(BusinessHours::isOpen($t, null, $dubai('2026-11-07 10:00')), 'Saturday off');
        $this->assertFalse(BusinessHours::isOpen($t, null, $dubai('2026-12-02 10:00')), 'National Day');
        $this->assertSame('Monday at 9:00', BusinessHours::nextOpenLabel($t, null, $dubai('2026-11-06 19:00')));
        $this->assertSame('Thursday at 9:00', BusinessHours::nextOpenLabel($t, null, $dubai('2026-12-01 20:00')), 'National Day (Wednesday) is skipped');
        // Friday 17:00 → Monday 10:00 = 1 h Friday + 1 h Monday.
        $this->assertSame(7200, BusinessHours::seconds($t, null, $dubai('2026-11-06 17:00'), $dubai('2026-11-09 10:00')));

        // A number with Friday–Saturday weekend and later hours.
        $week = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $week[$d] = ['open' => ! in_array($d, ['fri', 'sat'], true), 'from' => '10:00', 'to' => '22:00'];
        }
        $this->putJson('/api/v1/tenant/inbox-settings', ['number_hours' => [$this->number->id => $week]])->assertOk()
            ->assertJsonPath("data.number_hours.{$this->number->id}.fri.open", false);
        $t = $this->tenantContext()->bypass(fn () => $this->tenant->refresh());
        $this->assertTrue(BusinessHours::isOpen($t, $this->number->id, $dubai('2026-11-08 20:00')), 'Sunday evening open on this number');
        $this->assertFalse(BusinessHours::isOpen($t, null, $dubai('2026-11-08 20:00')));
        $this->assertSame([['date' => '2026-12-02', 'name' => 'National Day']], $this->getJson('/api/v1/tenant/inbox-settings')->json('data.holidays'));
    }

    public function test_the_after_hours_flow_answers_once_per_closed_period_and_never_during_opening_hours(): void
    {
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'After hours', 'phone_number_id' => $this->number->id, 'template' => 'after_hours'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();

        // Open: the after-hours flow stays out of it.
        $this->customer('Hello during the day');
        $this->assertSame([], $this->sent);

        // Thursday night: it answers, saying when the team is back; the chat stays Needs reply.
        $this->travelTo(Carbon::parse('2026-11-05 21:00', 'Asia/Dubai'));
        $this->customer('Hi, are you there?', '971500000009');
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('back tomorrow at 9:00', (string) ($this->sent[0]['interactive']['body']['text'] ?? ''));
        $c = $this->conversation('971500000009');
        $chat = $this->getJson("/api/v1/conversations/{$c->id}")->json('data');
        $this->assertSame('needs_reply', $chat['state']);
        $this->assertTrue($chat['after_hours']);

        // The customer ignores the buttons and the session is abandoned; later messages the same night get nothing more.
        $this->tenantContext()->run($this->tenant, fn () => ChatbotSession::query()->update(['status' => 'completed', 'ended_at' => now()]));
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['handler' => null, 'bot_session_id' => null]));
        $this->travel(30)->minutes();
        $this->customer('Hello??', '971500000009');
        $this->assertCount(1, $this->sent, 'one reply per closed period');
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame(1, DB::table('scheduled_actions')->where('kind', 'after_hours_reply')->count()));

        // The next closed period (Friday night) answers again.
        $this->travelTo(Carbon::parse('2026-11-06 20:00', 'Asia/Dubai'));
        $this->customer('Back again', '971500000009');
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('back Monday at 9:00', (string) ($this->sent[1]['interactive']['body']['text'] ?? ''));
    }

    public function test_reply_before_window_closes_is_flagged(): void
    {
        $this->customer('Hello');
        $c = $this->conversation();
        $this->travel(22 * 60 + 30)->minutes();
        $this->assertTrue($this->getJson("/api/v1/conversations/{$c->id}")->json('data.window.closing_soon'));
    }

    public function test_agents_see_needs_reply_counts_for_their_own_chats_only(): void
    {
        $this->customer('Mine', '971500000001');
        $this->customer('Not mine', '971500000002');
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, function () use ($agent) {
            app(NumberAccess::class)->setGrants($agent, [$this->number->id]);
            Conversation::query()->whereHas('contact', fn ($c) => $c->where('wa_id', '971500000001'))->update(['assigned_membership_id' => $agent->id]);
        });
        $this->actingAsMember($agent);
        $this->assertSame(1, $this->getJson('/api/v1/conversations/counts')->json('data.needs_reply'));
        $this->assertSame(1, $this->getJson('/api/v1/conversations/counts')->json('data.needs_reply_mine'));
    }
}
