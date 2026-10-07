<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Exceptions\MessageNotYetKnown;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageStatusEvent;
use App\Domain\Messaging\Services\StatusApplier;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class InboundMessagingTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->number = $this->connectNumber($this->tenant, display: '+971 58 549 6310');
    }

    /** @template T @param callable(): T $fn @return T */
    private function inTenant(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    public function test_inbound_text_creates_contact_conversation_and_opens_the_window(): void
    {
        $value = $this->inboundValue();
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();

        $this->inTenant(function () {
            $contact = Contact::query()->sole();
            $this->assertSame('971501234567', $contact->wa_id);
            $this->assertSame('AE.12345678901234567890', $contact->bsuid);
            $this->assertSame('Sara Ahmed', $contact->profile_name);

            $conversation = Conversation::query()->sole();
            $this->assertSame(1, $conversation->unread_count);
            $this->assertTrue($conversation->isWindowOpen());
            $this->assertTrue($conversation->window_expires_at->between(now()->addHours(23), now()->addHours(25)));

            $message = Message::query()->sole();
            $this->assertSame(Message::INBOUND, $message->direction);
            $this->assertSame(MessageStatus::Received, $message->status);
            $this->assertSame('Hi, do you have this in blue?', $message->body);
        });
    }

    public function test_the_same_wamid_is_stored_once_even_across_different_deliveries(): void
    {
        $value = $this->inboundValue(['id' => 'wamid.SAME']);
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();

        $value['messages'][0]['timestamp'] = (string) (now()->timestamp + 5); // retry with a different body hash
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();

        $this->inTenant(function () {
            $this->assertSame(1, Message::query()->count());
            $this->assertSame(1, Conversation::query()->sole()->unread_count);
        });
    }

    public function test_bsuid_only_users_are_supported_and_merged_when_the_phone_appears(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: null, name: 'Username User')))->assertOk();
        $this->inTenant(fn () => $this->assertNull(Contact::query()->sole()->wa_id));

        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();

        $this->inTenant(function () {
            $contact = Contact::query()->sole();
            $this->assertSame('971501234567', $contact->wa_id, 'phone back-filled onto the BSUID contact');
            $this->assertSame(2, Message::query()->where('contact_id', $contact->id)->count());
        });
    }

    public function test_stop_and_start_keywords_update_consent(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => ' STOP! ']])))->assertOk();
        $this->inTenant(fn () => $this->assertSame(ConsentState::OptedOut, Contact::query()->sole()->consent_state));

        // A sentence containing the word is not an opt-out.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'please do not stop sending offers']])))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => 'start']])))->assertOk();

        $this->inTenant(function () {
            $this->assertSame(ConsentState::OptedIn, Contact::query()->sole()->consent_state);
            $this->assertSame(['opted_out', 'opted_in'], ConsentEvent::query()->orderBy('id')->pluck('action')->all() /* UUIDv7: time-ordered */);
        });
    }

    public function test_statuses_only_move_forward_and_record_history(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();
        $out = $this->inTenant(fn () => Message::query()->create([
            'conversation_id' => Conversation::query()->sole()->id, 'phone_number_id' => $this->number->id,
            'contact_id' => Contact::query()->sole()->id, 'direction' => 'outbound', 'origin' => MessageOrigin::Agent,
            'type' => 'text', 'status' => MessageStatus::Accepted, 'wamid' => 'wamid.OUT1', 'body' => 'Yes!',
        ]));

        $status = fn (string $s, int $t) => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '106540352242922'],
            'statuses' => [['id' => 'wamid.OUT1', 'status' => $s, 'timestamp' => (string) $t, 'recipient_id' => '971501234567',
                'pricing' => ['billable' => false, 'pricing_model' => 'PMP', 'type' => 'free_customer_service', 'category' => 'service']]]];

        $this->postWebhook($this->webhookBody('messages', $status('read', 1767225700)))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $status('delivered', 1767225650)))->assertOk(); // late
        $this->postWebhook($this->webhookBody('messages', $status('failed', 1767225800)))->assertOk();   // after read: ignored

        $this->inTenant(function () use ($out) {
            $m = Message::query()->findOrFail($out->id);
            $this->assertSame(MessageStatus::Read, $m->status);
            $this->assertNotNull($m->getAttribute('delivered_at'));
            $this->assertNotNull($m->getAttribute('read_at'));
            $this->assertSame('service', $m->getAttribute('pricing')['category']);
            $this->assertSame(3, MessageStatusEvent::query()->where('message_id', $m->id)->count());
        });
    }

    public function test_a_status_for_an_unknown_wamid_is_retried_not_dropped(): void
    {
        $this->expectException(MessageNotYetKnown::class);

        $this->inTenant(fn () => app(StatusApplier::class)->apply(['id' => 'wamid.NOT_YET', 'status' => 'sent', 'timestamp' => '1767225600']));
    }

    public function test_app_echoes_are_mirrored_without_opening_the_window(): void
    {
        $this->postWebhook($this->webhookBody('smb_message_echoes', [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'],
            'message_echoes' => [['from' => '971585496310', 'to' => '971501234567', 'id' => 'wamid.ECHO1', 'timestamp' => (string) now()->timestamp,
                'type' => 'text', 'text' => ['body' => 'Sent from the Business app']]],
        ]))->assertOk();

        $this->inTenant(function () {
            $m = Message::query()->sole();
            $this->assertSame(MessageOrigin::AppEcho, $m->origin);
            $this->assertSame(Message::OUTBOUND, $m->direction);
            $c = Conversation::query()->sole();
            $this->assertFalse($c->isWindowOpen());
            $this->assertSame(0, $c->unread_count);
        });
    }

    public function test_history_import_keeps_direction_and_completes_the_sync(): void
    {
        $this->number = $this->tenantContext()->run($this->tenant, function () {
            $this->number->forceFill(['onboarding_type' => OnboardingType::Coexistence])->save();

            return $this->number;
        });

        $this->postWebhook($this->webhookBody('history', [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'],
            'history' => [[
                'metadata' => ['phase' => 2, 'chunk_order' => 1, 'progress' => 100],
                'threads' => [['id' => '971501234567', 'messages' => [
                    ['from' => '971585496310', 'id' => 'wamid.H1', 'timestamp' => '1739230955', 'type' => 'text', 'text' => ['body' => 'Your order shipped'], 'history_context' => ['status' => 'READ']],
                    ['from' => '971501234567', 'id' => 'wamid.H2', 'timestamp' => '1739230970', 'type' => 'text', 'text' => ['body' => 'Thanks!'], 'history_context' => ['status' => 'READ']],
                ]]],
            ]],
        ]))->assertOk();

        // Received records wait in the import list; the paced importer brings them in.
        $this->inTenant(fn () => $this->assertSame(0, Message::query()->count()));
        $this->artisan('engage:coexistence:import')->assertSuccessful();

        $this->inTenant(function () {
            $this->assertSame(['outbound', 'inbound'], Message::query()->orderBy('meta_timestamp')->orderBy('wamid')->pluck('direction')->all());
            $this->assertSame(MessageStatus::Read, Message::query()->where('wamid', 'wamid.H1')->sole()->status);
            $c = Conversation::query()->sole();
            $this->assertSame(0, $c->unread_count);
            $this->assertFalse($c->isWindowOpen(), 'history never opens a customer service window');
            $this->assertSame('synced', PhoneNumber::query()->findOrFail($this->number->id)->coexistence_status->value);
        });
    }

    public function test_inbound_media_is_downloaded_and_verified(): void
    {
        Storage::fake('local');
        $bytes = 'fake-jpeg-bytes';
        Http::fake([
            'graph.facebook.com/v25.0/MEDIA123*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'image/jpeg', 'sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes), 'id' => 'MEDIA123']),
            'lookaside.fbsbx.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->postWebhook($this->webhookBody('messages', $this->inboundValue([
            'type' => 'image', 'text' => null, 'image' => ['id' => 'MEDIA123', 'mime_type' => 'image/jpeg', 'sha256' => hash('sha256', $bytes), 'caption' => 'This one'],
        ])))->assertOk();

        $this->inTenant(function () use ($bytes) {
            $media = Media::query()->sole();
            $this->assertSame('ready', $media->status);
            Storage::disk('local')->assertExists((string) $media->path);
            $this->assertSame($bytes, Storage::disk('local')->get((string) $media->path));
            $this->assertSame('This one', Message::query()->sole()->body);
        });
    }

    public function test_bsuid_change_webhook_updates_the_contact(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();

        $this->postWebhook($this->webhookBody('user_id_update', [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '106540352242922'],
            'user_id_update' => [['wa_id' => '971501234567', 'user_id' => ['previous' => 'AE.12345678901234567890', 'current' => 'AE.99999999999999999999'], 'timestamp' => '1767225600']],
        ]))->assertOk();

        $this->inTenant(fn () => $this->assertSame('AE.99999999999999999999', Contact::query()->sole()->bsuid));
    }
}
