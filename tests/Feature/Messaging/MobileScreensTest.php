<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
            ->assertJsonCount(1, 'data.log');
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
}
