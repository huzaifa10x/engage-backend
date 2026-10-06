<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Notifications\TemplateRejectedNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class AutoReplyAndEmailsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);

        Http::fake(['graph.facebook.com/v25.0/106540352242922/messages*' => fn () => Http::response([
            'messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.AUTO'.bin2hex(random_bytes(6))]],
        ])]);
    }

    private function inbound(string $text): void
    {
        $value = $this->inboundValue();
        $value['messages'][0] = ['from' => '971501234567', 'id' => 'wamid.IN'.bin2hex(random_bytes(6)), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]];
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();
    }

    private function autoReplies(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages') && str_contains((string) ($r['text']['body'] ?? ''), 'back to you'))->count();
    }

    public function test_auto_reply_is_off_by_default_and_answers_once_per_period_when_switched_on(): void
    {
        $this->getJson('/api/v1/tenant/auto-reply')->assertOk()->assertJsonPath('data.enabled', false);
        $this->inbound('Hello');
        $this->assertSame(0, $this->autoReplies());

        $this->putJson('/api/v1/tenant/auto-reply', ['enabled' => true, 'message' => 'Thanks 🙏 we will get back to you shortly.', 'cooldown_hours' => 12])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.cooldown_hours', 12);

        $this->inbound('Are you open today?');
        $this->assertSame(1, $this->autoReplies());
        Http::assertSent(fn (Request $r) => ($r['text']['body'] ?? null) === 'Thanks 🙏 we will get back to you shortly.');
        $this->assertSame('automation', $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('direction', 'outbound')->sole()->origin->value));

        $this->inbound('Hello??');            // same conversation, within 12 hours → no second auto reply
        $this->assertSame(1, $this->autoReplies());

        $this->travel(13)->hours();
        $this->inbound('Anyone there?');
        $this->assertSame(2, $this->autoReplies());
    }

    public function test_auto_reply_can_be_cancelled_for_one_conversation_and_never_answers_stop(): void
    {
        $this->putJson('/api/v1/tenant/auto-reply', ['enabled' => true, 'message' => 'We will get back to you.'])->assertOk();
        $this->inbound('Hi');
        $this->assertSame(1, $this->autoReplies());

        $conversation = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->firstOrFail());
        $this->patchJson("/api/v1/conversations/{$conversation->id}", ['auto_reply_enabled' => false])->assertOk()->assertJsonPath('data.auto_reply_enabled', false);

        $this->travel(2)->days();
        $this->inbound('Still waiting');
        $this->assertSame(1, $this->autoReplies()); // cancelled for this conversation

        $this->patchJson("/api/v1/conversations/{$conversation->id}", ['auto_reply_enabled' => true])->assertOk();
        $this->inbound('STOP');               // an opt-out gets its own confirmation, not the auto reply
        $this->assertSame(1, $this->autoReplies());

        // Only people who may change settings can change the auto reply.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->putJson('/api/v1/tenant/auto-reply', ['enabled' => false])->assertForbidden();
    }

    public function test_the_owner_is_emailed_when_meta_rejects_a_template(): void
    {
        Http::fake(['graph.facebook.com/v25.0/102290129340398/message_templates*' => Http::response(['data' => [
            ['id' => '900002', 'name' => 'promo_june', 'language' => 'en_US', 'status' => 'PENDING', 'category' => 'MARKETING', 'components' => [['type' => 'BODY', 'text' => 'Sale now on']]],
        ]])]);
        $this->postJson('/api/v1/templates/sync')->assertOk();
        Notification::fake();

        $rejected = ['event' => 'REJECTED', 'message_template_id' => 900002, 'message_template_name' => 'promo_june', 'message_template_language' => 'en_US', 'reason' => 'INVALID_FORMAT'];
        $this->postWebhook($this->webhookBody('message_template_status_update', $rejected))->assertOk();

        $email = $this->tenantContext()->bypass(fn () => $this->owner->user()->value('email'));
        Notification::assertSentOnDemand(TemplateRejectedNotification::class, fn (TemplateRejectedNotification $n, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === $email
            && $n->template === 'promo_june' && $n->reason === 'INVALID_FORMAT' && str_contains((string) $n->toMail($to)->subject, 'promo_june'));
        Notification::assertSentOnDemandTimes(TemplateRejectedNotification::class, 1);
    }
}
