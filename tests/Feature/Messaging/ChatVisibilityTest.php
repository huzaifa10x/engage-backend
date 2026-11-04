<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Events\ConversationAssigned;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Chat visibility: Owners and Admins see every chat; Agents only the chats assigned to them. */
final class ChatVisibilityTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->number = $this->connectNumber($this->tenant);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.V1'], '971500000001', 'AE.1', 'One')))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.V2'], '971500000002', 'AE.2', 'Two')))->assertOk();
    }

    private function agent(): TenantMembership
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($agent, [$this->number->id]));

        return $agent;
    }

    private function conversation(string $waId): Conversation
    {
        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', $waId))->sole());
    }

    /** @return list<string> */
    private function listed(): array
    {
        return collect($this->getJson('/api/v1/conversations')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_the_owner_keeps_seeing_a_chat_after_assigning_it(): void
    {
        $owner = $this->addMember($this->tenant, SystemRole::Owner);
        $agent = $this->agent();
        $c = $this->conversation('971500000001');

        $this->actingAsMember($owner)->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $agent->id])->assertOk();
        $this->assertContains($c->id, $this->listed());
        $this->getJson("/api/v1/conversations/{$c->id}")->assertOk();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $this->assertContains($c->id, $this->listed());
    }

    public function test_agents_see_only_their_own_chats_everywhere(): void
    {
        $owner = $this->addMember($this->tenant, SystemRole::Owner);
        $a = $this->agent();
        $b = $this->agent();
        $one = $this->conversation('971500000001');
        $two = $this->conversation('971500000002');
        $this->actingAsMember($owner)->patchJson("/api/v1/conversations/{$one->id}", ['assigned_membership_id' => $a->id])->assertOk();
        $this->patchJson("/api/v1/conversations/{$two->id}", ['assigned_membership_id' => $b->id])->assertOk();

        $this->actingAsMember($a);
        $this->assertSame([$one->id], $this->listed());
        $this->getJson("/api/v1/conversations/{$two->id}")->assertForbidden();
        $this->getJson("/api/v1/conversations/{$two->id}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$two->id}/messages", ['type' => 'text', 'body' => 'hi'])->assertForbidden();
        $this->getJson("/api/v1/conversations/{$two->id}/notes")->assertForbidden();
        $this->getJson("/api/v1/conversations/{$one->id}/messages")->assertOk();
        $d = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
        $this->assertSame(1, $d['open']);
        $this->assertSame(1, $d['messages_received_today']);
        $unread = collect($this->getJson('/api/v1/notifications')->assertOk()->json('data.messages'))->pluck('conversation_id')->all();
        $this->assertNotContains($two->id, $unread);

        // Reassigning moves the chat between agents; the owner keeps seeing both.
        $this->actingAsMember($owner)->patchJson("/api/v1/conversations/{$two->id}", ['assigned_membership_id' => $a->id])->assertOk();
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $this->listed());
        $this->actingAsMember($a);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $this->listed());
        $this->actingAsMember($b);
        $this->assertSame([], $this->listed());
    }

    public function test_assignment_changes_reach_the_owner_and_both_agents_in_realtime_and_agents_cannot_join_number_channels(): void
    {
        Event::fake([ConversationAssigned::class]);
        $owner = $this->addMember($this->tenant, SystemRole::Owner);
        $a = $this->agent();
        $c = $this->conversation('971500000001');

        $this->actingAsMember($owner)->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $a->id])->assertOk();
        Event::assertDispatched(ConversationAssigned::class, function (ConversationAssigned $e) use ($a) {
            $names = array_map(fn ($ch) => $ch->name, $e->broadcastOn());

            return in_array("private-tenant.{$this->tenant->id}.number.{$this->number->id}", $names, true)
                && in_array("private-tenant.{$this->tenant->id}.member.{$a->id}", $names, true);
        });

        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $auth = fn (TenantMembership $m, string $channel, array $params) => $this->tenantContext()->run(
            $this->tenant, fn () => $channels[$channel]($m->user, ...$params), $m->loadMissing('role'),
        );
        $number = 'tenant.{tenantId}.number.{phoneNumberId}';
        $member = 'tenant.{tenantId}.member.{membershipId}';
        $this->assertFalse($auth($a, $number, [$this->tenant->id, $this->number->id]), 'agents never get every chat on a number');
        $this->assertTrue($auth($a, $member, [$this->tenant->id, $a->id]));
        $this->assertFalse($auth($a, $member, [$this->tenant->id, $owner->id]), 'nor someone else\'s channel');
        $this->assertTrue($auth($owner, $number, [$this->tenant->id, $this->number->id]));
    }

    public function test_a_chat_an_agent_starts_is_theirs(): void
    {
        Http::fake(['graph.facebook.com/*/messages*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.OUT1']]])]);
        $a = $this->agent();
        $contactId = $this->conversation('971500000001')->contact_id;
        $this->actingAsMember($a);
        $this->assertSame([], $this->listed());

        $this->postJson('/api/v1/messages', ['phone_number_id' => $this->number->id, 'contact_id' => $contactId, 'type' => 'text', 'body' => 'Hello'])->assertSuccessful();
        $this->assertSame([$this->conversation('971500000001')->id], $this->listed());

        // A chat that belongs to another agent cannot be taken over this way.
        $b = $this->agent();
        $this->actingAsMember($b)->postJson('/api/v1/messages', ['phone_number_id' => $this->number->id, 'contact_id' => $contactId, 'type' => 'text', 'body' => 'Mine?'])->assertForbidden();
    }
}
