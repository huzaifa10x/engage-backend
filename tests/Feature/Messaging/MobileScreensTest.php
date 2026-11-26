<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * The phone's Home and Agents screens (mobile brief, owner view): the 7-day figures on Home, Today on
 * Agents with the reply log, and one teammate's chats.
 */
final class MobileScreensTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->travelTo(Carbon::parse('2026-11-23 09:00', 'Asia/Dubai'));
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]])]);
        $this->actingAsMember($this->owner);
    }

    private function customerWritesAndOwnerReplies(int $afterMinutes): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'Hello'], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();
        $c = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
        $this->travel($afterMinutes)->minutes();
        $this->actingAsMember($this->owner);
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Hi there'])->assertStatus(202);

        return $c;
    }

    public function test_home_shows_the_last_seven_days(): void
    {
        $this->customerWritesAndOwnerReplies(4);

        $week = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.week');
        $this->assertCount(7, $week['days']);
        $this->assertSame('2026-11-23', $week['days'][6]);
        $this->assertSame(1, $week['new_enquiries'][6]);
        $this->assertSame(0, $week['new_enquiries'][0]);
        $this->assertSame(1, $week['replies']);
        $this->assertSame(100, $week['on_time_pct']);
        $this->assertNotNull($week['first_reply_median_seconds'][6]);
        $this->assertNull($week['first_reply_median_seconds'][0]);
        $this->assertSame(0, $week['won']);
    }

    public function test_agents_today_and_the_reply_log(): void
    {
        $this->customerWritesAndOwnerReplies(4);

        $this->getJson('/api/v1/analytics/response-times?days=1')->assertOk()
            ->assertJsonPath('data.range.days', 1)
            ->assertJsonPath('data.log.0.result', 'on_time')
            ->assertJsonPath('data.log.0.by', $this->owner->user?->name)
            ->assertJsonCount(1, 'data.log')
            ->assertJsonPath('data.first_response.slow', 0)->assertJsonPath('data.first_response.late', 0);

        // One teammate: their slowest replies.
        $this->getJson("/api/v1/analytics/response-times?days=1&membership_id={$this->owner->id}")->assertOk()->assertJsonCount(1, 'data.log');
        $this->getJson('/api/v1/analytics/response-times?days=1&membership_id='.Str::uuid())->assertOk()->assertJsonCount(0, 'data.log');
    }

    public function test_one_teammates_chats(): void
    {
        $sara = $this->addMember($this->tenant, SystemRole::Agent);
        $c = $this->customerWritesAndOwnerReplies(1);
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['assigned_membership_id' => $sara->id]));

        $this->getJson("/api/v1/conversations?assignee={$sara->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/conversations?assignee={$this->owner->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_people_are_listed_a_to_z(): void
    {
        foreach (['Zara', 'adam', 'Mona'] as $i => $name) {
            $this->postJson('/api/v1/contacts', ['name' => $name, 'phone' => '+97150123450'.$i])->assertSuccessful();
        }

        $names = array_column($this->getJson('/api/v1/contacts?sort=name')->assertOk()->json('data'), 'display_name');
        $this->assertSame(['adam', 'Mona', 'Zara'], $names);
    }

    public function test_the_action_centre_counts_and_the_closing_filter(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'Hi'], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();
        $this->actingAsMember($this->owner);
        $conv = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.action.closing_soon', 0);

        // 23 hours later the window closes within 2 hours: counted, and the filter lists it.
        $this->travel(23)->hours();
        $this->actingAsMember($this->owner);
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.action.closing_soon', 1)
            ->assertJsonPath('data.action.followups_overdue', 0)->assertJsonPath('data.action.mentions_unread', 0);
        $this->getJson('/api/v1/conversations?window=closing&state=needs_reply')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $conv->id);

        $this->postJson("/api/v1/contacts/{$conv->contact_id}/next-steps", ['type' => 'call', 'title' => 'Call back', 'due_at' => now()->subHour()->toIso8601String()])->assertCreated();
        $this->getJson('/api/v1/dashboard/summary')->assertJsonPath('data.action.followups_overdue', 1);
    }

    public function test_follow_ups_by_due_date_mine_and_everyone(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'Hi'], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();
        $this->actingAsMember($this->owner);
        $conv = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
        $sara = $this->addMember($this->tenant, SystemRole::Agent);
        $step = fn (string $title, string $due, ?string $to = null) => $this->postJson("/api/v1/contacts/{$conv->contact_id}/next-steps", ['type' => 'call', 'title' => $title, 'due_at' => $due] + ($to ? ['assigned_membership_id' => $to] : []))->assertCreated()->json('data.id');

        $late = $step('Call back', now()->subHour()->toIso8601String());
        $step('Send quote', now()->addHour()->toIso8601String());   // 10:00 + 1 h: today
        $step('Check in', now()->addDays(3)->toIso8601String());
        $step('Sara’s call', now()->addHour()->toIso8601String(), $sara->id);

        $res = $this->getJson('/api/v1/next-steps?bucket=overdue')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Call back')->assertJsonPath('data.0.chat_id', $conv->id)->assertJsonPath('data.0.contact.id', $conv->contact_id);
        $this->assertSame(['overdue' => 1, 'today' => 1, 'upcoming' => 1, 'done' => 0], $res->json('meta.counts'));
        $this->assertSame(2, $this->getJson('/api/v1/next-steps?bucket=today&who=everyone')->json('meta.counts.today'));

        // Complete with undo, reschedule.
        $this->patchJson("/api/v1/next-steps/{$late}", ['status' => 'done'])->assertOk();
        $this->assertSame(1, $this->getJson('/api/v1/next-steps?bucket=done')->json('meta.counts.done'));
        $this->patchJson("/api/v1/next-steps/{$late}", ['status' => 'open', 'due_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->assertSame(['overdue' => 0, 'today' => 1, 'upcoming' => 2, 'done' => 0], $this->getJson('/api/v1/next-steps')->json('meta.counts'));

        // An agent sees only their own, even when asking for everyone.
        $this->actingAsMember($sara);
        $this->getJson('/api/v1/next-steps?bucket=today&who=everyone')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.everyone', false);
    }
}
