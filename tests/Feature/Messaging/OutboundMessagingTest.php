<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class OutboundMessagingTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const SEND = 'graph.facebook.com/v25.0/106540352242922/messages*';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
    }

    private function fakeSend(?array $error = null): void
    {
        Http::fake([
            self::SEND => $error
                ? Http::response(['error' => $error], 400)
                : Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '971501234567', 'wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(4))]]]),
        ]);
    }

    /** Creates a thread via a real inbound webhook (window open). */
    private function thread(?string $waId = '971501234567', ?string $bsuid = 'AE.12345678901234567890'): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: $waId, bsuid: $bsuid)))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
    }

    public function test_agent_reply_is_sent_and_tracked(): void
    {
        $conversation = $this->thread();
        $this->fakeSend();
        $this->actingAsMember($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Yes, we have it in blue.'])
            ->assertStatus(202)->assertJsonPath('data.direction', 'outbound');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/106540352242922/messages')
            && $r['to'] === '971501234567' && $r['type'] === 'text' && $r['text']['body'] === 'Yes, we have it in blue.'
            && $r['messaging_product'] === 'whatsapp');

        $this->tenantContext()->run($this->tenant, function () use ($conversation) {
            $m = Message::query()->where('direction', 'outbound')->sole();
            $this->assertSame(MessageStatus::Accepted, $m->status);
            $this->assertStringStartsWith('wamid.OUT', (string) $m->wamid);
            $this->assertSame($this->owner->id, Conversation::query()->findOrFail($conversation->id)->assigned_membership_id);
        });
    }

    public function test_emoji_reach_whatsapp_unchanged_and_are_stored_exactly(): void
    {
        $conversation = $this->thread();
        $this->fakeSend();
        $this->actingAsMember($this->owner);
        // Single emoji, skin-tone modifier, a joined family sequence, a flag, and Arabic text beside them.
        $text = 'Thanks 😀👍🏽 👨‍👩‍👧 🇦🇪 شكراً ❤️';

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => $text])
            ->assertStatus(202)->assertJsonPath('data.body', $text);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/106540352242922/messages') && $r['text']['body'] === $text
            && json_decode($r->body(), true)['text']['body'] === $text);
        $this->assertSame($text, $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('direction', 'outbound')->sole()->body));
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk()->assertJsonFragment(['body' => $text]);

    }

    public function test_free_form_is_blocked_outside_the_window_but_templates_are_allowed(): void
    {
        $conversation = $this->thread();
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($conversation->id)->update(['window_expires_at' => now()->subMinute()]));
        $this->fakeSend();
        $this->actingAsMember($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Hello again'])
            ->assertStatus(422)->assertJsonPath('error.code', 'window_closed');

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'type' => 'template', 'template' => ['name' => 'order_update', 'language' => 'en', 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '#1042']]]]],
        ])->assertStatus(202);

        Http::assertSent(fn (Request $r) => $r['type'] === 'template' && $r['template']['name'] === 'order_update' && $r['template']['language']['code'] === 'en');
    }

    public function test_opted_out_contacts_cannot_be_messaged(): void
    {
        $conversation = $this->thread();
        $this->tenantContext()->run($this->tenant, fn () => Contact::query()->update(['consent_state' => ConsentState::OptedOut]));
        $this->actingAsMember($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Hi'])
            ->assertStatus(422)->assertJsonPath('error.code', 'contact_opted_out');
    }

    public function test_idempotency_key_prevents_duplicate_sends(): void
    {
        $conversation = $this->thread();
        $this->fakeSend();
        $this->actingAsMember($this->owner);

        foreach ([1, 2] as $_) {
            $this->withHeader('Idempotency-Key', 'client-msg-42')
                ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Once only'])->assertStatus(202);
        }

        Http::assertSentCount(1);
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame(1, Message::query()->where('direction', 'outbound')->count()));
    }

    public function test_permanent_meta_errors_mark_the_message_failed(): void
    {
        $conversation = $this->thread();
        $this->fakeSend(['message' => '(#131026) Message undeliverable', 'code' => 131026, 'fbtrace_id' => 'T1']);
        $this->actingAsMember($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertStatus(202);

        $this->tenantContext()->run($this->tenant, function () {
            $m = Message::query()->where('direction', 'outbound')->sole();
            $this->assertSame(MessageStatus::Failed, $m->status);
            $this->assertSame('131026', $m->error_code);
        });
    }

    public function test_bsuid_only_contacts_are_addressed_by_recipient(): void
    {
        $conversation = $this->thread(waId: null);
        $this->fakeSend();
        $this->actingAsMember($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertStatus(202);

        Http::assertSent(fn (Request $r) => ($r['recipient'] ?? null) === 'AE.12345678901234567890' && ! isset($r['to']));
    }

    public function test_image_upload_then_send_uploads_to_meta_once(): void
    {
        Storage::fake('local');
        $conversation = $this->thread();
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media*' => Http::response(['id' => 'META-MEDIA-1']),
            self::SEND => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '971501234567', 'wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.IMG1']]]),
        ]);
        $this->actingAsMember($this->owner);

        // Real JPEG bytes (the PHP image has no GD, so fake()->image() is unavailable).
        $jpeg = (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $mediaId = $this->post('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('blue.jpg', $jpeg)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.type', 'image')->json('data.id');

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'image', 'media_id' => $mediaId, 'body' => 'Here it is'])->assertStatus(202);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/media') && $r->isMultipart());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['image']['id'] === 'META-MEDIA-1' && $r['image']['caption'] === 'Here it is');
    }

    public function test_starting_a_conversation_by_phone_with_a_template(): void
    {
        $this->fakeSend();
        $this->actingAsMember($this->owner);

        $this->postJson('/api/v1/messages', [
            'phone_number_id' => $this->number->id, 'to' => '+971 50 765 4321',
            'type' => 'template', 'template' => ['name' => 'hello_world', 'language' => 'en_US'],
        ])->assertStatus(202);

        Http::assertSent(fn (Request $r) => $r['to'] === '971507654321');
        $this->tenantContext()->run($this->tenant, fn () => $this->assertSame('971507654321', Contact::query()->sole()->wa_id));
    }

    public function test_agents_only_see_and_reply_on_granted_numbers(): void
    {
        $conversation = $this->thread();
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->actingAsMember($agent);

        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => 'x'])->assertForbidden();

        $this->actingAsMember($this->owner)->putJson("/api/v1/team/members/{$agent->id}/numbers", ['phone_number_ids' => [$this->number->id]])->assertOk();

        $this->actingAsMember($agent)->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.window.open', true)->assertJsonPath('data.0.contact.display_name', 'Sara Ahmed');
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reading_a_conversation_clears_unread_and_sends_blue_ticks(): void
    {
        $conversation = $this->thread();
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->actingAsMember($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertOk()->assertJsonPath('data.unread_count', 0);

        Http::assertSent(fn (Request $r) => ($r['status'] ?? null) === 'read' && str_starts_with((string) $r['message_id'], 'wamid.IN'));
    }
}
