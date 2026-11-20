<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Inbox 3.5: priority order, the Filters panel, search over messages, events, unread, health, Fields & sync. */
final class InboxListTest extends TestCase
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
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.X'.bin2hex(random_bytes(4))]]])]);
        $this->actingAsMember($this->owner);
    }

    private function customer(string $from, string $text): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['from' => $from, 'text' => ['body' => $text], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($c) => $c->where('wa_id', $from))->sole());
    }

    public function test_priority_filters_search_and_the_card_fields(): void
    {
        $old = $this->customer('971501110001', 'First, window closes soonest');
        $this->travel(2)->hours();
        $new = $this->customer('971501110002', 'Second, needs a quote for pricing');
        $this->travel(1)->minutes();
        $this->tenantContext()->run($this->tenant, fn () => $new->forceFill(['window_expires_at' => now()->subMinute()])->save());   // window closed

        $ids = array_column($this->getJson('/api/v1/conversations?sort=priority&status=open')->assertOk()->json('data'), 'id');
        $this->assertSame([$old->id, $new->id], $ids);     // open window before closed
        $this->getJson('/api/v1/conversations?sort=priority&window=closed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new->id);
        $this->getJson('/api/v1/conversations?sort=newest&q=quote')->assertJsonCount(1, 'data');                 // message text
        $this->getJson('/api/v1/conversations?sort=priority&followup=1')->assertJsonCount(0, 'data');

        $contact = $old->contact_id;
        $this->postJson("/api/v1/contacts/{$contact}/next-steps", ['type' => 'call', 'title' => 'Call back', 'due_at' => now()->subHour()->toIso8601String()])->assertCreated();
        $row = $this->getJson('/api/v1/conversations?sort=priority&followup=1')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame([1, 'Call', 'customer'], [$row['next_step']['overdue'], $row['next_step']['type_label'], $row['last_message_by']['kind']]);

        $this->postJson("/api/v1/conversations/{$old->id}/read")->assertOk();
        $this->postJson("/api/v1/conversations/{$old->id}/unread")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->postJson("/api/v1/conversations/{$old->id}/snooze", ['until' => now()->addHour()->toIso8601String()])->assertOk();
        $this->assertNotContains($old->id, array_column($this->getJson('/api/v1/conversations?sort=priority')->json('data'), 'id'));
        $this->assertContains($old->id, array_column($this->getJson('/api/v1/conversations?sort=priority&include_snoozed=1')->json('data'), 'id'));
        $types = array_column($this->getJson("/api/v1/conversations/{$old->id}/events")->assertOk()->json('data'), 'type');
        $this->assertContains('conversation.snoozed', $types);
        $this->assertContains('next_step.added', $types);

        $health = $this->getJson('/api/v1/inbox/health')->assertOk()->json('data');
        $this->assertSame([0, 0, 2], [$health['expired'], $health['in_time'], $health['pending']]);
        $this->postJson("/api/v1/conversations/{$old->id}/typing", ['typing' => true])->assertOk();
    }

    public function test_fields_and_sync_keeps_the_stage_map_and_stays_not_connected(): void
    {
        $this->postJson('/api/v1/crm/stages/suggested');
        $map = $this->getJson('/api/v1/crm/sync')->assertOk()->assertJsonPath('data.provider', 'none')->json('data.stage_map');
        $won = collect($map)->firstWhere('stage.group', 'won');
        $this->assertSame('closedwon', $won['hubspot']);
        $first = $map[0]['stage']['id'];
        $this->putJson('/api/v1/crm/sync', ['stage_map' => [$first => ['hubspot' => 'qualifiedtobuy', 'zoho' => 'Needs Analysis']]])->assertOk()
            ->assertJsonPath('data.stage_map.0.hubspot', 'qualifiedtobuy');
    }
}
