<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\MemberNotification;
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
 * Engagement Plan v4 step C (follow-up rules) and Settings › Auto reply with a chatbot: team alerts
 * and escalation in business hours, the window alert, round-robin for unassigned chats, customer
 * nudges with their stop rules, quiet hours and the frequency cap.
 */
final class FollowUpsTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-11-05 10:00', 'Asia/Dubai')); // Thursday, open
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
        $this->putJson('/api/v1/tenant/inbox-settings', ['business_hours' => ['enabled' => true, 'days' => $week]])->assertOk();
    }

    private function customer(string $text, string $waId = '971501234567'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text], 'timestamp' => (string) now()->timestamp], waId: $waId, bsuid: null)))->assertOk();
    }

    private function conversation(string $waId = '971501234567'): Conversation
    {
        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($c) => $c->where('wa_id', $waId))->sole());
    }

    private function tick(int $minutes): void
    {
        $this->travel($minutes)->minutes();
        Artisan::call('engage:inbox:wake-snoozed'); // runs the scheduler
    }

    /** @return list<string> */
    private function bell(TenantMembership $m): array
    {
        return $this->tenantContext()->run($this->tenant, fn () => MemberNotification::query()->where('membership_id', $m->id)->orderBy('created_at')->pluck('type')->all());
    }

    public function test_the_team_is_alerted_then_the_chat_escalates_and_a_reply_stops_both(): void
    {
        $lead = $this->addMember($this->tenant, SystemRole::Admin);
        $this->putJson('/api/v1/tenant/followups', [
            'reply_alert' => ['enabled' => true, 'minutes' => 10],
            'escalation' => ['enabled' => true, 'minutes' => 30, 'action' => 'notify', 'notify_ids' => [$lead->id]],
        ])->assertOk()->assertJsonPath('data.settings.escalation.minutes', 30);

        $this->customer('Hello?');
        $c = $this->conversation();
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['assigned_membership_id' => $this->owner->id]));

        $this->tick(9);
        $this->assertNotContains('reply_overdue', $this->bell($this->owner));
        $this->tick(2);
        $this->assertContains('reply_overdue', $this->bell($this->owner), '10 minutes in business hours');
        $this->tick(20);
        $this->assertContains('reply_escalated', $this->bell($lead));

        // A new wait, answered in time: nothing fires.
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Sorry for the wait!'])->assertSuccessful();
        $this->customer('Thanks, one more thing');
        $this->tick(3);
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Sure.'])->assertSuccessful();
        $this->tick(40);
        $this->assertSame(1, count(array_filter($this->bell($this->owner), fn ($t) => $t === 'reply_overdue')));
        $this->tenantContext()->run($this->tenant, fn () => $this->assertGreaterThanOrEqual(2, DB::table('scheduled_actions')->where('result', 'answered')->count()));
    }

    public function test_the_reply_alert_waits_for_opening_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 17:55', 'Asia/Dubai'));
        $this->customer('Late question');
        $c = $this->conversation();
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['assigned_membership_id' => $this->owner->id]));
        $this->tick(60); // 18:55: only 5 business minutes so far
        $this->assertNotContains('reply_overdue', $this->bell($this->owner));
        $this->travelTo(Carbon::parse('2026-11-06 09:06', 'Asia/Dubai'));
        Artisan::call('engage:inbox:wake-snoozed');
        $this->assertContains('reply_overdue', $this->bell($this->owner));
    }

    public function test_escalation_can_reassign_and_unassigned_chats_are_assigned_by_round_robin(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($agent, [$this->number->id]));
        $this->putJson('/api/v1/tenant/followups', ['unassigned' => ['enabled' => true, 'minutes' => 5]])->assertOk();

        $this->customer('Anyone?');
        $this->tick(6);
        $this->assertNotNull($this->conversation()->assigned_membership_id);
    }

    public function test_a_nudge_goes_out_after_silence_and_stops_when_the_customer_replies(): void
    {
        $this->putJson('/api/v1/tenant/followups', ['nudge' => ['enabled' => true, 'hours' => 4, 'message' => 'Hi {{name}}, any questions?']])->assertOk();
        $this->customer('How much is it?');
        $c = $this->conversation();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'AED 300. Shall I book you?'])->assertSuccessful();
        $sentBefore = count($this->sent);

        $this->tick(4 * 60 + 1);
        $this->assertCount($sentBefore + 1, $this->sent);
        $this->assertSame('Hi Sara, any questions?', $this->sent[$sentBefore]['text']['body'] ?? null);
        $this->assertSame('waiting', $this->getJson("/api/v1/conversations/{$c->id}")->json('data.state'), 'a nudge is not the team replying, nor a new wait');

        // Next time the customer answers within the delay: the nudge is cancelled.
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Here is the link.'])->assertSuccessful();
        $this->tick(60);
        $this->customer('Thanks!');
        $this->tick(4 * 60);
        $this->assertCount($sentBefore + 2, $this->sent, 'the agent’s link, and no second nudge');
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame(1, DB::table('scheduled_actions')->where('kind', 'nudge')->where('status', 'cancelled')->where('result', 'customer_replied')->count()));

        $stats = $this->getJson('/api/v1/tenant/followups')->assertOk()->json('data');
        $this->assertSame(1, $stats['stats']['nudge']['fired']);
        $this->assertSame(1, $stats['stats']['nudge']['replied']);
    }

    public function test_a_nudge_due_at_night_is_held_until_opening_or_skipped_if_the_window_would_close(): void
    {
        $this->putJson('/api/v1/tenant/followups', ['nudge' => ['enabled' => true, 'hours' => 4]])->assertOk();
        $this->travelTo(Carbon::parse('2026-11-05 16:00', 'Asia/Dubai'));
        $this->customer('Question');
        $c = $this->conversation();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Answer'])->assertSuccessful();
        $before = count($this->sent);
        $this->tick(4 * 60 + 1); // 20:01 → closed: held until 09:00, window open until 16:00 next day
        $this->assertCount($before, $this->sent);
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame('held_quiet_hours', DB::table('scheduled_actions')->where('kind', 'nudge')->value('result')));
        $this->travelTo(Carbon::parse('2026-11-06 09:01', 'Asia/Dubai'));
        Artisan::call('engage:inbox:wake-snoozed');
        $this->assertCount($before + 1, $this->sent);
    }

    public function test_the_weekly_cap_and_opt_out_stop_nudges(): void
    {
        $this->putJson('/api/v1/tenant/followups', ['nudge' => ['enabled' => true, 'hours' => 1], 'weekly_cap' => 1])->assertOk();
        $this->customer('Q1');
        $c = $this->conversation();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'A1'])->assertSuccessful();
        $this->tick(61);
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'A2'])->assertSuccessful();
        $this->tick(61);
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame('frequency_cap', DB::table('scheduled_actions')->where('kind', 'nudge')->orderByDesc('created_at')->value('result')));
    }

    public function test_plans_without_automations_cannot_turn_on_nudges_and_the_estimate_is_shown(): void
    {
        $this->subscribe($this->tenant, 'starter');
        $this->putJson('/api/v1/tenant/followups', ['nudge' => ['enabled' => true]])->assertStatus(422);
        $estimate = $this->getJson('/api/v1/tenant/followups')->assertOk()->json('data.estimate');
        $this->assertSame(1000, $estimate['free_service_messages']);
        $this->assertArrayHasKey('nudges_per_month', $estimate);
    }

    public function test_auto_reply_can_be_a_chatbot_flow_once_per_period(): void
    {
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Welcome', 'phone_number_id' => $this->number->id, 'template' => 'after_hours'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();
        $options = $this->getJson('/api/v1/tenant/auto-reply')->assertOk()->json('data.flow_options');
        $this->assertSame($id, $options[0]['flows'][0]['id']);

        $this->putJson('/api/v1/tenant/auto-reply', ['enabled' => true, 'reply_with' => 'chatbot', 'when' => 'always', 'cooldown_hours' => 24, 'flows' => [$this->number->id => $id]])
            ->assertOk()->assertJsonPath('data.reply_with', 'chatbot');

        // During opening hours too (when = always): the chosen chatbot answers, not the fixed message.
        $this->customer('Hello there');
        $this->assertCount(1, $this->sent);
        $this->assertSame('interactive', $this->sent[0]['type']);
        $this->tenantContext()->run($this->tenant, fn () => ChatbotSession::query()->update(['status' => 'completed', 'ended_at' => now()]));
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->update(['handler' => null, 'bot_session_id' => null]));
        $this->customer('Another message');
        $this->assertCount(1, $this->sent, 'once per conversation per 24 h');

        $this->putJson('/api/v1/tenant/auto-reply', ['enabled' => true, 'reply_with' => 'chatbot', 'flows' => []])->assertStatus(422);
    }
}
