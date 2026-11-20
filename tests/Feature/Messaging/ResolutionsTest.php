<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Inbox & CRM 3.5: every closure is its own record with a reason; reopenings, undo, flags and the weekly review. */
final class ResolutionsTest extends TestCase
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

    private function customer(string $text): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->sole());
    }

    public function test_each_closure_is_its_own_row_with_its_reason_and_how_it_was_reopened(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($agent, [$this->number->id]));
        $c = $this->customer('Hi');
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $agent->id])->assertOk();

        $this->actingAsMember($agent);
        $this->patchJson("/api/v1/conversations/{$c->id}", ['status' => 'closed', 'reason' => 'not_lead'])->assertOk()
            ->assertJsonPath('data.resolution.reason', 'not_lead')->assertJsonPath('data.resolution.reason_label', 'Not a lead / spam');
        $this->travel(5)->minutes();
        $this->customer('Actually, one more question');                       // reopened by the customer
        $this->travel(5)->minutes();
        $this->patchJson("/api/v1/conversations/{$c->id}", ['status' => 'closed'])->assertOk();

        $rows = $this->getJson('/api/v1/resolutions')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['id'], $rows[1]['id']);
        $this->assertSame(['resolved', null, 0], [$rows[0]['reason'], $rows[0]['reopened_at'], $rows[0]['later_closures']]);
        $this->assertSame(['not_lead', 'customer', 1], [$rows[1]['reason'], $rows[1]['reopen_how'], $rows[1]['later_closures']]);
        $this->assertSame($agent->id, $rows[1]['resolved_by']['id']);
        $this->getJson('/api/v1/resolutions?reason=not_lead')->assertJsonCount(1, 'data');

        // Agents can't flag; a manager can: the chat reopens, goes back to the agent, a note says why.
        $this->postJson("/api/v1/resolutions/{$rows[0]['id']}/flag", ['note' => 'Customer asked about pricing'])->assertForbidden();
        $manager = $this->addMember($this->tenant, SystemRole::Manager);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($manager, [$this->number->id]));
        $this->actingAsMember($manager);
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => null])->assertOk();
        $this->postJson("/api/v1/resolutions/{$rows[0]['id']}/flag", ['note' => 'Customer asked about pricing'])->assertOk()
            ->assertJsonPath('data.flag.note', 'Customer asked about pricing')->assertJsonPath('data.reopen_how', 'flag');
        $chat = $this->getJson("/api/v1/conversations/{$c->id}")->json('data');
        $this->assertSame(['open', $agent->id], [$chat['status'], $chat['assigned_membership_id']]);
        $this->assertStringContainsString('Flagged for review', $this->tenantContext()->run($this->tenant, fn () => ConversationNote::query()->latest()->value('body')));

        $review = $this->getJson('/api/v1/resolutions/review')->assertOk()->json('data.agents.0');
        $this->assertSame([2, 1, 1], [$review['resolved'], $review['not_lead'], $review['flagged']]);
    }

    public function test_undo_right_after_resolving_removes_the_closure(): void
    {
        $c = $this->customer('Hello?');
        $this->patchJson("/api/v1/conversations/{$c->id}", ['status' => 'closed', 'reason' => 'no_action'])->assertOk();
        $this->patchJson("/api/v1/conversations/{$c->id}", ['status' => 'open', 'undo' => true])->assertOk()
            ->assertJsonPath('data.state', 'needs_reply');
        $this->assertCount(0, $this->getJson('/api/v1/resolutions')->json('data'));
    }
}
