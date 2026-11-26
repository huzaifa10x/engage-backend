<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Reply alerts (mobile brief, owner view): Fast 5 / 15 / 30 by default, Standard and Relaxed presets;
 * at the second step a holding message naming the agent and, for teams of 3+, a move to a free agent;
 * the agent's name in the first reply of each conversation.
 */
final class ReplyAlertPresetsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const SEND = 'graph.facebook.com/v27.0/106540352242922/messages*';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        // Monday 23 November 2026, 09:00 in Dubai; open every day 08:00–22:00.
        $this->travelTo(Carbon::parse('2026-11-23 09:00', 'Asia/Dubai'));
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai', 'name' => 'Desert Drive']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->owner->user?->forceFill(['name' => 'Waqar Ali'])->save();
        $this->number = $this->connectNumber($this->tenant);
        Http::fake([self::SEND => function ($r) {
            $this->sent[] = $r->data();

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]]);
        }]);
        $this->actingAsMember($this->owner);
        $week = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $week[$d] = ['open' => true, 'from' => '08:00', 'to' => '22:00'];
        }
        $this->putJson('/api/v1/tenant/inbox-settings', ['business_hours' => ['enabled' => true, 'days' => $week]])->assertOk();
        $this->putJson('/api/v1/tenant/followups', ['reply_alert' => ['enabled' => false]])->assertOk(); // only the ladder here
    }

    private function customer(string $text = 'Hi, is the SUV free this weekend?'): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
    }

    private function tick(int $minutes): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 09:00', 'Asia/Dubai')->addMinutes($minutes));
        Artisan::call('engage:inbox:wake-snoozed');
        $this->actingAsMember($this->owner);
    }

    /** @return list<string> text bodies sent to the customer */
    private function texts(): array
    {
        return array_values(array_filter(array_map(fn (array $d) => $d['text']['body'] ?? null, $this->sent)));
    }

    public function test_fast_is_the_default_and_presets_set_the_steps(): void
    {
        $this->getJson('/api/v1/tenant/reply-promise')->assertOk()
            ->assertJsonPath('data.preset', 'fast')->assertJsonPath('data.limit_minutes', 30)
            ->assertJsonPath('data.ladder.0.minutes', 5)->assertJsonPath('data.ladder.1.minutes', 15)->assertJsonPath('data.ladder.2.minutes', 30)
            ->assertJsonPath('data.hold', true)->assertJsonPath('data.intro', true)->assertJsonPath('data.reassign', true);

        // The inbox colours a wait from the steps: amber from 5 minutes, red from 15.
        $c = $this->customer();
        $this->getJson("/api/v1/conversations/{$c->id}")->assertOk()->assertJsonPath('data.promise.alert_minutes', [5, 15, 30]);

        $this->putJson('/api/v1/tenant/reply-promise', ['preset' => 'standard'])->assertOk()
            ->assertJsonPath('data.limit_minutes', 45)->assertJsonPath('data.ladder.0.minutes', 15)->assertJsonPath('data.ladder.2.minutes', 45);
        $this->putJson('/api/v1/tenant/reply-promise', ['preset' => 'relaxed'])->assertOk()
            ->assertJsonPath('data.limit_minutes', 90)->assertJsonPath('data.ladder.1.minutes', 60);

        // Times entered by hand are Custom, and stay as entered.
        $this->putJson('/api/v1/tenant/reply-promise', ['limit_minutes' => 20, 'ladder' => [['minutes' => 10, 'to' => 'agent'], ['minutes' => 20, 'to' => 'owner']]])->assertOk()
            ->assertJsonPath('data.preset', 'custom')->assertJsonPath('data.limit_minutes', 20)->assertJsonPath('data.ladder.1.to', 'owner');
        $this->putJson('/api/v1/tenant/reply-promise', ['preset' => 'fast'])->assertOk()->assertJsonPath('data.limit_minutes', 30)->assertJsonPath('data.ladder.0.minutes', 5);

        // A workspace that had saved its own ladder before presets keeps it, as Custom.
        $this->legacyPromise($this->tenant);
        $this->getJson('/api/v1/tenant/reply-promise')->assertOk()->assertJsonPath('data.preset', 'custom')->assertJsonPath('data.ladder.0.minutes', 30);
    }

    public function test_the_first_reply_of_a_conversation_carries_the_agents_name(): void
    {
        $c = $this->customer();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Yes, it is free.'])->assertStatus(202);
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Shall I book it?'])->assertStatus(202);

        $name = $this->number->verified_name ?: 'Desert Drive';
        $this->assertSame(["Waqar here from {$name} 👋\nYes, it is free.", 'Shall I book it?'], $this->texts());

        // Switched off: replies go as typed.
        $this->putJson('/api/v1/tenant/reply-promise', ['intro' => false])->assertOk();
        $other = $this->customer();
        $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('conversation_id', $other->id)->where('origin', MessageOrigin::Agent->value)->delete());
        $this->sent = [];
        $this->postJson("/api/v1/conversations/{$other->id}/messages", ['type' => 'text', 'body' => 'Hello again'])->assertStatus(202);
        $this->assertSame(['Hello again'], $this->texts());
    }

    public function test_at_the_second_step_the_customer_hears_who_replies_and_the_chat_moves_to_a_free_agent(): void
    {
        $sara = $this->addMember($this->tenant, SystemRole::Agent);
        $sara->user?->forceFill(['name' => 'Sara Haddad'])->save();
        $ahmed = $this->addMember($this->tenant, SystemRole::Agent);
        $ahmed->user?->forceFill(['name' => 'Ahmed Khan'])->save();
        $this->tenantContext()->run($this->tenant, function () use ($sara, $ahmed) {
            app(NumberAccess::class)->setGrants($sara, [$this->number->id]);
            app(NumberAccess::class)->setGrants($ahmed, [$this->number->id]);
        });

        $c = $this->customer();
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['assigned_membership_id' => $sara->id]));

        $this->tick(6);   // first step (5 min): only the team is told
        $this->assertSame([], $this->texts());

        $this->tick(16);  // second step (15 min): moved to a free agent, then the holding message names them
        $c = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->findOrFail($c->id));
        $this->assertNotSame($sara->id, $c->assigned_membership_id);
        $to = $c->assigned_membership_id === $ahmed->id ? 'Ahmed' : 'Waqar';
        $this->assertSame(["Thanks Sara, {$to} will reply to you in a few minutes."], $this->texts());

        // It never counts as a reply: the chat still needs one, and a later step sends nothing more.
        $this->getJson("/api/v1/conversations/{$c->id}")->assertOk()->assertJsonPath('data.state', 'needs_reply');
        $this->tick(31);
        $this->assertCount(1, $this->texts());
    }

    public function test_small_teams_are_not_reassigned_and_both_can_be_switched_off(): void
    {
        $sara = $this->addMember($this->tenant, SystemRole::Agent); // two people reply: no move
        $sara->user?->forceFill(['name' => 'Sara Haddad'])->save();
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($sara, [$this->number->id]));

        $c = $this->customer();
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['assigned_membership_id' => $sara->id]));
        $this->tick(16);
        $this->assertSame($sara->id, $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->findOrFail($c->id))->assigned_membership_id);
        $this->assertSame(['Thanks Sara, Sara will reply to you in a few minutes.'], $this->texts());

        // Holding message off: the next wait sends nothing to the customer.
        $this->putJson('/api/v1/tenant/reply-promise', ['hold' => false])->assertOk()->assertJsonPath('data.hold', false);
        $this->sent = [];
        $this->travelTo(Carbon::parse('2026-11-23 12:00', 'Asia/Dubai'));
        $other = $this->customer('Another question');
        $this->travelTo(Carbon::parse('2026-11-23 12:20', 'Asia/Dubai'));
        Artisan::call('engage:inbox:wake-snoozed');
        $this->assertSame([], $this->texts());
        $this->assertNotNull($other->id);
    }

    public function test_the_holding_message_is_part_of_automations(): void
    {
        // Free (also a paid plan whose renewal failed): no automations, so no message goes to the customer.
        $this->subscribe($this->tenant, 'free');
        $c = $this->customer();
        $this->tick(16);
        $this->assertSame([], $this->texts());
        $this->assertNotNull($c->id);
    }
}
