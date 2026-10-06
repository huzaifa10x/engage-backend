<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Notifications\InboxAlertNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class InboxToolsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);
        Http::fake(['graph.facebook.com/v25.0/106540352242922/messages*' => fn () => Http::response([
            'messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.X'.bin2hex(random_bytes(6))]],
        ])]);
    }

    private function inbound(string $from = '971501234567', string $text = 'Hello'): Conversation
    {
        $value = $this->inboundValue(waId: $from, bsuid: 'AE.'.str_pad($from, 20, '0', STR_PAD_LEFT)); // a different person per number
        $value['messages'][0] = ['from' => $from, 'id' => 'wamid.IN'.bin2hex(random_bytes(6)), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]];
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', $from))->firstOrFail());
    }

    /** Agents only work on the numbers they were given. */
    private function agentWithNumberAccess(): TenantMembership
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($agent, [$this->number->id]));

        return $agent;
    }

    public function test_canned_responses_are_saved_within_the_plan_limit(): void
    {
        $id = $this->postJson('/api/v1/canned-responses', ['shortcut' => 'hours', 'body' => 'We are open 9am to 6pm, Monday to Friday. 🕘'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/canned-responses', ['shortcut' => 'hours', 'body' => 'Duplicate'])->assertStatus(422);
        $this->postJson('/api/v1/canned-responses', ['shortcut' => 'Has Spaces', 'body' => 'x'])->assertStatus(422);
        $this->putJson("/api/v1/canned-responses/{$id}", ['shortcut' => 'opening-hours', 'body' => 'Open 9 to 6.'])->assertOk()->assertJsonPath('data.shortcut', 'opening-hours');
        $this->getJson('/api/v1/canned-responses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Open 9 to 6.');
        $this->getJson('/api/v1/tenant/entitlements')->assertJsonPath('data.features.canned_responses.used', 1);

        // Another workspace sees none of them.
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/canned-responses')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/canned-responses/{$id}", ['shortcut' => 'x', 'body' => 'y'])->assertNotFound();

        $this->actingAsMember($this->owner);
        $this->deleteJson("/api/v1/canned-responses/{$id}")->assertOk();
        $this->getJson('/api/v1/canned-responses')->assertJsonCount(0, 'data');
    }

    public function test_internal_notes_stay_internal_and_a_mention_notifies_the_teammate(): void
    {
        Notification::fake();
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $conversation = $this->inbound();

        $this->postJson("/api/v1/conversations/{$conversation->id}/notes", ['body' => 'Customer wants a refund, please check the order.', 'mentions' => [$agent->id]])->assertCreated();

        $this->getJson("/api/v1/conversations/{$conversation->id}/notes")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mine', true)->assertJsonPath('data.0.body', 'Customer wants a refund, please check the order.')->assertJsonCount(1, 'data.0.mentions');
        // A note is never a WhatsApp message.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages'));
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertJsonCount(1, 'data');

        Notification::assertSentTo($agent->user()->first(), InboxAlertNotification::class, fn (InboxAlertNotification $n) => str_contains($n->title, 'mentioned you') && str_ends_with($n->url, "/inbox?c={$conversation->id}"));

        $this->actingAsMember($agent);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.unread', 1)->assertJsonPath('data.items.0.type', 'mention');
        $this->postJson('/api/v1/notifications/read')->assertOk()->assertJsonPath('data.unread', 0)->assertJsonPath('data.items.0.read', true);
    }

    public function test_a_snoozed_conversation_leaves_the_inbox_and_returns_on_time_or_when_the_customer_writes(): void
    {
        $conversation = $this->inbound();
        $this->patchJson("/api/v1/conversations/{$conversation->id}", ['assigned_membership_id' => $this->owner->id])->assertOk();

        $this->postJson("/api/v1/conversations/{$conversation->id}/snooze", ['until' => now()->subHour()->toIso8601String()])->assertStatus(422);
        $this->postJson("/api/v1/conversations/{$conversation->id}/snooze", ['until' => now()->addHours(3)->toIso8601String()])->assertOk()->assertJsonPath('data.snoozed_until', fn (?string $u) => $u !== null);

        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/conversations?snoozed=1')->assertOk()->assertJsonCount(1, 'data');

        // Time passes → it comes back and the assignee is told.
        $this->travel(4)->hours();
        Artisan::call('engage:inbox:wake-snoozed');
        $this->actingAsMember($this->owner);
        $this->getJson('/api/v1/conversations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.snoozed_until', null);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.items.0.type', 'snooze_ended');

        // Snoozed again, but the customer writes → back immediately.
        $this->postJson("/api/v1/conversations/{$conversation->id}/snooze", ['until' => now()->addDay()->toIso8601String()])->assertOk();
        $this->inbound(text: 'Any update?');
        $this->getJson('/api/v1/conversations')->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/conversations/{$conversation->id}/snooze", ['until' => now()->addDay()->toIso8601String()])->assertOk();
        $this->deleteJson("/api/v1/conversations/{$conversation->id}/snooze")->assertOk()->assertJsonPath('data.snoozed_until', null);
    }

    public function test_business_hours_and_round_robin_routing(): void
    {
        Notification::fake();
        $hours = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], ['open' => true, 'from' => '09:00', 'to' => '18:00']) + array_fill_keys(['sat', 'sun'], ['open' => false, 'from' => '09:00', 'to' => '18:00']);

        $bad = $hours;
        $bad['mon'] = ['open' => true, 'from' => '18:00', 'to' => '09:00'];
        $this->putJson('/api/v1/tenant/inbox-settings', ['business_hours' => ['enabled' => true, 'days' => $bad]])->assertStatus(422);
        $this->putJson('/api/v1/tenant/inbox-settings', ['business_hours' => ['enabled' => true, 'days' => $hours], 'routing' => 'round_robin'])
            ->assertOk()->assertJsonPath('data.routing', 'round_robin')->assertJsonPath('data.business_hours.enabled', true);

        // Auto reply "only outside business hours": silent on Tuesday morning, sent on Saturday (Dubai time).
        $this->putJson('/api/v1/tenant/auto-reply', ['enabled' => true, 'message' => 'We are closed right now and will reply when we open.', 'when' => 'outside_hours'])->assertOk();
        $this->travelTo(now('Asia/Dubai')->next('Tuesday')->setTime(10, 0));
        $this->getJson('/api/v1/tenant/inbox-settings')->assertJsonPath('data.open_now', true);
        $first = $this->inbound('971501110001');
        Http::assertNotSent(fn (Request $r) => str_contains((string) ($r['text']['body'] ?? ''), 'closed right now'));

        // Routing: each new conversation goes to whoever can reply on that number and has the fewest open ones.
        $agent = $this->agentWithNumberAccess();
        $second = $this->inbound('971501110002');
        $third = $this->inbound('971501110003');
        $assigned = fn (Conversation $c) => $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->find($c->id)?->assigned_membership_id);
        $this->assertSame($this->owner->id, $assigned($first));     // only the owner existed then
        $this->assertSame($agent->id, $assigned($second));          // the agent had none
        $this->assertContains($assigned($third), [$this->owner->id, $agent->id]);
        Notification::assertSentTo($agent->user()->first(), InboxAlertNotification::class);

        $this->travelTo(now('Asia/Dubai')->next('Saturday')->setTime(11, 0));
        $this->getJson('/api/v1/tenant/inbox-settings')->assertJsonPath('data.open_now', false);
        $this->inbound('971501110004');
        Http::assertSent(fn (Request $r) => str_contains((string) ($r['text']['body'] ?? ''), 'closed right now'));

        // Agents cannot change these settings.
        $this->actingAsMember($agent);
        $this->putJson('/api/v1/tenant/inbox-settings', ['routing' => 'manual'])->assertForbidden();
    }

    public function test_dashboard_numbers_and_assignment_notification(): void
    {
        Notification::fake();
        $agent = $this->agentWithNumberAccess();
        $one = $this->inbound('971501110001');
        $this->inbound('971501110002');
        $this->postJson("/api/v1/conversations/{$one->id}/messages", ['type' => 'text', 'body' => 'Hi, how can we help?'])->assertStatus(202);

        $this->getJson('/api/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('data.conversations_today', 2)->assertJsonPath('data.open', 2)
            ->assertJsonPath('data.waiting_for_reply', 1)->assertJsonPath('data.messages_received_today', 2)->assertJsonPath('data.messages_sent_today', 1);

        // Giving a conversation to a teammate tells them; taking one yourself does not.
        $this->patchJson("/api/v1/conversations/{$one->id}", ['assigned_membership_id' => $agent->id])->assertOk();
        Notification::assertSentTo($agent->user()->first(), InboxAlertNotification::class, fn (InboxAlertNotification $n) => str_contains($n->title, 'assigned a conversation to you'));
        $this->actingAsMember($agent);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.unread', 1)->assertJsonPath('data.items.0.type', 'assigned');
    }
}
