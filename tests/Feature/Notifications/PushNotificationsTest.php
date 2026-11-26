<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Notifications\Models\PushSubscription;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Infrastructure\Push\WebPush;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Phone notifications (mobile brief, launch must-have 1): turning a phone on, a customer's message
 * reaching whoever should answer (encrypted, signed, with the waiting-count badge), choices per
 * kind, gone subscriptions removed, and the test notification that counts only once tapped.
 */
final class PushNotificationsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const PUSH = 'https://fcm.googleapis.com/fcm/send/';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var array<string, array{0: string, 1: string, 2: string}> endpoint => [ua private, ua public, auth] */
    private array $phones = [];

    /** @var list<array{endpoint: string, payload: array<string, mixed>, headers: array<string, mixed>}> */
    private array $pushed = [];

    private int $status = 201;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        [$public, $private] = WebPush::generateKeys();
        config(['engage.push.vapid_public_key' => $public, 'engage.push.vapid_private_key' => $private, 'engage.push.subject' => 'mailto:test@example.com']);
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]]),
            self::PUSH.'*' => function (HttpRequest $r) {
                $this->pushed[] = ['endpoint' => $r->url(), 'payload' => $this->decrypt($r->url(), $r->body()), 'headers' => $r->headers()];

                return Http::response('', $this->status);
            },
        ]);
    }

    /** Turns on notifications on a (simulated) phone for the member signed in now; returns its endpoint. */
    private function phone(string $name): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $d = str_pad($ec['d'], 32, "\0", STR_PAD_LEFT);
        $pub = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        $endpoint = self::PUSH.$name;
        $this->phones[$endpoint] = [$d, $pub, $auth];
        $this->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => WebPush::b64($pub), 'auth' => WebPush::b64($auth)], 'label' => $name])->assertCreated();

        return $endpoint;
    }

    /** What the phone's browser does: decrypt the push (RFC 8291) and read the JSON. */
    private function decrypt(string $endpoint, string $body): array
    {
        [$d, $pub, $auth] = $this->phones[$endpoint];
        $salt = substr($body, 0, 16);
        $asPub = substr($body, 21, ord($body[20]));
        $ct = substr($body, 21 + ord($body[20]));
        $secret = openssl_pkey_derive(WebPush::publicPem($asPub), WebPush::privatePem($d, $pub), 32);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$pub.$asPub, $auth);
        $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt), OPENSSL_RAW_DATA,
            hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt), substr($ct, -16));

        return json_decode(rtrim((string) $plain, "\x02"), true);
    }

    private function customerWrites(string $text = 'Is the SUV free?'): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
    }

    public function test_a_new_chat_reaches_the_owners_phone_encrypted_and_signed(): void
    {
        $this->actingAsMember($this->owner);
        $phone = $this->phone('owner-phone');
        $this->getJson('/api/v1/push?endpoint='.urlencode($phone))->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.this_device.label', 'owner-phone')->assertJsonCount(1, 'data.devices');

        $c = $this->customerWrites();
        $this->assertCount(1, $this->pushed);
        $push = $this->pushed[0];
        $this->assertSame("/inbox?c={$c->id}", $push['payload']['url']);
        $this->assertStringEndsWith('· new chat', $push['payload']['title']);
        $this->assertSame('Is the SUV free?', $push['payload']['body']);
        $this->assertSame(1, $push['payload']['badge']); // one chat waiting: the app-icon number
        $this->assertSame('aes128gcm', $push['headers']['Content-Encoding'][0]);

        // The VAPID signature checks out with the server's public key.
        preg_match('/vapid t=([^,]+), k=(.+)/', $push['headers']['Authorization'][0], $m);
        [$h, $p, $sig] = explode('.', $m[1]);
        $raw = WebPush::unb64($sig);
        $der = fn (string $i) => "\x02".chr(strlen(ltrim($i, "\0")) + (ord(ltrim($i, "\0")[0]) > 127 ? 1 : 0)).(ord(ltrim($i, "\0")[0]) > 127 ? "\0" : '').ltrim($i, "\0");
        $seq = $der(substr($raw, 0, 32)).$der(substr($raw, 32));
        $this->assertSame(1, openssl_verify("{$h}.{$p}", "\x30".chr(strlen($seq)).$seq, WebPush::publicPem(WebPush::unb64($m[2])), OPENSSL_ALGO_SHA256));
        $this->assertSame('https://fcm.googleapis.com', json_decode(WebPush::unb64($p), true)['aud']);

        // A burst of messages is one notification (the phone replaces it), not three.
        $this->customerWrites('Hello?');
        $this->assertCount(1, $this->pushed);
    }

    public function test_an_assigned_chat_only_reaches_the_assignee(): void
    {
        $sara = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($sara, [$this->number->id]));
        $this->actingAsMember($this->owner);
        $this->phone('owner-phone');
        $this->actingAsMember($sara);
        $saraPhone = $this->phone('sara-phone');

        $c = $this->customerWrites();
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['assigned_membership_id' => $sara->id]));
        $this->pushed = [];
        $this->travel(1)->minutes();
        $this->customerWrites('Any update?');

        $this->assertSame([$saraPhone], array_column($this->pushed, 'endpoint'));
        $this->assertSame('Any update?', $this->pushed[0]['payload']['body']);
    }

    public function test_choices_message_text_and_gone_phones(): void
    {
        $this->actingAsMember($this->owner);
        $phone = $this->phone('owner-phone');

        // Message text off: the lock screen only says who wrote.
        $this->putJson('/api/v1/push/preferences', ['message_text' => false])->assertOk()->assertJsonPath('data.message_text', false);
        $this->customerWrites('Private details');
        $this->assertSame('New message', $this->pushed[0]['payload']['body']);

        // New messages off: nothing for the next chat. Account problems can't be switched off.
        $this->putJson('/api/v1/push/preferences', ['messages' => false, 'account' => false])->assertOk()
            ->assertJsonPath('data.categories.0.enabled', false)->assertJsonPath('data.categories.4.enabled', true);
        $this->pushed = [];
        $this->travel(1)->minutes();
        $this->customerWrites();
        $this->assertSame([], $this->pushed);

        // Assignment notices still come (their own kind); a phone the push service says is gone is removed.
        $this->status = 410;
        $sara = $this->addMember($this->tenant, SystemRole::Manager);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($sara, [$this->number->id]));
        $c = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
        $this->actingAsMember($sara);
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $this->owner->id])->assertOk();
        $this->assertCount(1, $this->pushed);
        $this->assertSame('assigned', $this->pushed[0]['payload']['kind']);
        $this->assertFalse(PushSubscription::query()->where('endpoint_hash', PushSubscription::hash($phone))->exists());
    }

    public function test_the_test_notification_counts_once_tapped(): void
    {
        $this->actingAsMember($this->owner);
        $phone = $this->phone('owner-phone');
        $device = $this->postJson('/api/v1/push/test', ['endpoint' => $phone])->assertOk()->json('data');
        $this->assertNotNull($device['test_sent_at']);
        $this->assertNull($device['test_tapped_at']);
        $this->assertSame("/inbox?n=test&s={$device['id']}", $this->pushed[0]['payload']['url']);

        $this->postJson('/api/v1/push/test/tapped', ['id' => $device['id']])->assertOk()->assertJsonPath('data.id', $device['id']);
        $this->getJson('/api/v1/push?endpoint='.urlencode($phone))->assertJsonPath('data.this_device.id', $device['id']);
        $this->assertNotNull(PushSubscription::query()->findOrFail($device['id'])->test_tapped_at);

        // Turning it off on this phone.
        $this->deleteJson('/api/v1/push/subscriptions', ['endpoint' => $phone])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
    }
}
