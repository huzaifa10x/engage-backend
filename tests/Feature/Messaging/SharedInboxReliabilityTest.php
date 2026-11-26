<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Shared inbox rules and reliable sending (mobile P0): a chat is never silently taken from the teammate
 * who just got it, and a reply stuck in sending is queued again, or failed with Retry, never left
 * on a clock.
 */
final class SharedInboxReliabilityTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    private int $sends = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        Http::fake(['graph.facebook.com/*' => function () {
            $this->sends++;

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]]);
        }]);
        $this->actingAsMember($this->owner);
    }

    private function customer(): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'Hello'], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();
        $this->actingAsMember($this->owner);

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
    }

    public function test_a_chat_is_not_taken_from_a_teammate_who_just_got_it(): void
    {
        $sara = $this->addMember($this->tenant, SystemRole::Manager);
        $sara->user?->forceFill(['name' => 'Sara Haddad'])->save();
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($sara, [$this->number->id]));
        $c = $this->customer();

        // Sara takes it first; the owner's take, made from the unassigned list a moment later, is refused.
        $this->actingAsMember($sara);
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $sara->id, 'expected_assigned_membership_id' => null])->assertOk();
        $this->actingAsMember($this->owner);
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $this->owner->id, 'expected_assigned_membership_id' => null])
            ->assertStatus(409)->assertJsonPath('code', 'assignment_changed')->assertJsonPath('assigned_membership_id', $sara->id)
            ->assertJsonPath('message', 'Sara Haddad has just been given this chat. Open it to reassign if you need to.');
        $this->assertSame($sara->id, $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->findOrFail($c->id))->assigned_membership_id);

        // Reassigning on purpose, having seen that Sara has it, works; so does repeating your own take.
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $this->owner->id, 'expected_assigned_membership_id' => $sara->id])->assertOk();
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $this->owner->id, 'expected_assigned_membership_id' => null])->assertOk();
        // Without an expected assignee it behaves as before.
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => null])->assertOk();
    }

    public function test_a_reply_stuck_in_sending_is_queued_again_once(): void
    {
        $c = $this->customer();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'On my way'])->assertStatus(202);
        $this->assertSame(1, $this->sends);
        $id = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('conversation_id', $c->id)->where('direction', 'outbound')->value('id'));

        // A worker died before it was sent: still queued 11 minutes later.
        $this->tenantContext()->run($this->tenant, fn () => Message::query()->whereKey($id)->update(['status' => MessageStatus::Queued->value, 'wamid' => null, 'updated_at' => now()->subMinutes(11)]));
        Artisan::call('engage:messages:requeue-stuck');
        $this->assertSame(2, $this->sends);
        $this->assertSame(MessageStatus::Accepted, $this->tenantContext()->run($this->tenant, fn () => Message::query()->findOrFail($id))->status);

        // Running again sends nothing more.
        Artisan::call('engage:messages:requeue-stuck');
        $this->assertSame(2, $this->sends);
    }

    public function test_after_six_hours_it_fails_with_retry(): void
    {
        $c = $this->customer();
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'On my way'])->assertStatus(202);
        $id = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('conversation_id', $c->id)->where('direction', 'outbound')->value('id'));
        $this->tenantContext()->run($this->tenant, fn () => Message::query()->whereKey($id)->update(['status' => MessageStatus::Queued->value, 'updated_at' => now()->subHours(7)]));

        Artisan::call('engage:messages:requeue-stuck');
        $m = $this->tenantContext()->run($this->tenant, fn () => Message::query()->findOrFail($id));
        $this->assertSame(MessageStatus::Failed, $m->status);
        $this->assertSame('send_stuck', $m->error_code);

        // The chat offers Retry, and it goes.
        $this->getJson("/api/v1/conversations/{$c->id}/messages")->assertOk();
        $this->postJson("/api/v1/conversations/{$c->id}/messages/{$id}/retry")->assertStatus(202);
        $this->assertSame(MessageStatus::Accepted, $this->tenantContext()->run($this->tenant, fn () => Message::query()->findOrFail($id))->status);
    }
}
