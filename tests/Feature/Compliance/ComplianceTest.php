<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class ComplianceTest extends TestCase
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
            'messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(6))]],
        ])]);
    }

    /** @param array<string, mixed> $message */
    private function inbound(array $message): void
    {
        $value = $this->inboundValue();
        $value['messages'][0] = $message + ['from' => '971501234567', 'id' => 'wamid.IN'.bin2hex(random_bytes(6)), 'timestamp' => (string) time()];
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();
    }

    private function text(string $body): void
    {
        $this->inbound(['type' => 'text', 'text' => ['body' => $body]]);
    }

    private function contact(): Contact
    {
        return $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', '971501234567')->firstOrFail());
    }

    public function test_stop_and_start_are_recorded_in_the_ledger_and_confirmed_once(): void
    {
        $this->text('Hello');
        $this->text('STOP');

        $this->assertSame('opted_out', $this->contact()->consent_state->value);
        // The only message an opted-out contact still gets: the confirmation.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && str_contains((string) ($r['text']['body'] ?? ''), 'unsubscribed'));
        Http::assertSentCount(1);

        $this->text('stop'); // again: no second confirmation, no second ledger row
        Http::assertSentCount(1);

        $this->text('Start');
        $this->assertSame('opted_in', $this->contact()->consent_state->value);
        Http::assertSentCount(2);

        $this->getJson('/api/v1/compliance/consent-events')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'opted_in')->assertJsonPath('data.0.source', 'keyword')->assertJsonPath('data.0.detail', 'Start')
            ->assertJsonPath('data.1.action', 'opted_out')->assertJsonPath('data.1.contact.phone', '+971501234567');
        $this->getJson('/api/v1/compliance/consent-events?action=opted_out&q=5012')->assertJsonCount(1, 'data');

        $export = $this->get('/api/v1/compliance/consent-events/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('+971501234567', $export);
        $this->assertStringContainsString('opted_out,keyword,STOP', $export);
    }

    public function test_keywords_and_replies_are_configurable_but_stop_can_never_be_removed(): void
    {
        $this->putJson('/api/v1/compliance/settings', [
            'opt_out_keywords' => ['Leave me alone', 'BAS'], 'opt_in_keywords' => ['join', 'bas'],
            'opt_out_reply' => 'Done — you will not hear from us again.', 'confirm_opt_in' => false,
        ])->assertOk()
            ->assertJsonPath('data.opt_out_keywords', ['stop', 'unsubscribe', 'leave me alone', 'bas'])
            ->assertJsonPath('data.opt_in_keywords', ['start', 'subscribe', 'join']); // "bas" cannot mean both: opt-out wins

        $this->text('please leave me alone today'); // not a whole-message match
        $this->assertSame('unknown', $this->contact()->consent_state->value);

        $this->text('Leave me alone!');
        $this->assertSame('opted_out', $this->contact()->consent_state->value);
        Http::assertSent(fn (Request $r) => ($r['text']['body'] ?? null) === 'Done — you will not hear from us again.');

        $this->text('JOIN');
        $this->assertSame('opted_in', $this->contact()->consent_state->value);
        Http::assertSentCount(1); // opt-in confirmation is switched off

        // Another workspace still uses the defaults.
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/compliance/settings')->assertOk()->assertJsonMissing(['leave me alone']);
        $this->getJson('/api/v1/compliance/consent-events')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_in_chat_subscribe_button_records_an_explicit_opt_in(): void
    {
        $this->text('Hi, do you have offers?');
        $conversation = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->firstOrFail());

        $this->postJson("/api/v1/conversations/{$conversation->id}/consent-request")->assertStatus(202)->assertJsonPath('data.type', 'interactive');
        Http::assertSent(fn (Request $r) => ($r['type'] ?? null) === 'interactive' && $r['interactive']['action']['buttons'][0]['reply']['title'] === 'Subscribe');

        $this->inbound(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'consent_opt_in', 'title' => 'Subscribe']]]);

        $this->assertSame('opted_in', $this->contact()->consent_state->value);
        $this->getJson('/api/v1/compliance/consent-events')->assertJsonPath('data.0.action', 'opted_in')->assertJsonPath('data.0.source', 'in_chat_button');
    }

    public function test_overview_reports_consent_coverage_and_policy_checks(): void
    {
        $this->text('STOP');
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110001', 'opted_in' => true])->assertCreated();
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110002'])->assertCreated();

        $overview = $this->getJson('/api/v1/compliance/overview')->assertOk()
            ->assertJsonPath('data.contacts.total', 3)->assertJsonPath('data.contacts.opted_in', 1)
            ->assertJsonPath('data.contacts.opted_out', 1)->assertJsonPath('data.contacts.unknown', 1)
            ->assertJsonPath('data.last_30_days.opt_outs', 1)->assertJsonPath('data.last_30_days.opt_ins', 1);

        $checks = collect($overview->json('data.checks'))->keyBy('key');
        $this->assertSame('ok', $checks['opt_out_keywords']['status']);
        $this->assertSame('info', $checks['retention']['status']);

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/compliance/overview')->assertForbidden();
        $this->putJson('/api/v1/compliance/settings', ['confirm_opt_out' => false])->assertForbidden();
    }

    public function test_retention_redacts_old_messages_and_deletes_old_media_but_keeps_the_record(): void
    {
        Storage::fake('local');
        $this->text('An old message with personal details');
        $this->text('A recent message');

        [$old, $recent, $media] = $this->tenantContext()->run($this->tenant, function () {
            $messages = Message::query()->orderBy('created_at')->orderBy('id')->get();
            $media = Media::query()->create(['direction' => 'inbound', 'mime_type' => 'image/jpeg', 'status' => 'ready', 'disk' => 'local', 'path' => 'media/old.jpg', 'filename' => 'old.jpg']);
            Storage::disk('local')->put('media/old.jpg', 'bytes');
            Message::query()->whereKey($messages[0]->id)->update(['created_at' => now()->subDays(120), 'media_id' => $media->id]);
            Media::query()->whereKey($media->id)->update(['created_at' => now()->subDays(120)]);

            return [$messages[0]->id, $messages[1]->id, $media->id];
        });

        // Not enabled → nothing happens.
        Artisan::call('engage:retention:apply');
        $this->assertNotNull($this->tenantContext()->run($this->tenant, fn () => Message::query()->find($old)?->body));

        $this->putJson('/api/v1/compliance/settings', ['retention_enabled' => true, 'message_retention_days' => 10])->assertStatus(422); // below the minimum
        $this->putJson('/api/v1/compliance/settings', ['retention_enabled' => true, 'message_retention_days' => 90, 'media_retention_days' => 30])->assertOk();
        Artisan::call('engage:retention:apply');
        $this->actingAsMember($this->owner);

        $this->tenantContext()->run($this->tenant, function () use ($old, $recent, $media): void {
            $redacted = Message::query()->findOrFail($old);
            $this->assertNull($redacted->body);
            $this->assertNull($redacted->media_id);
            $this->assertNotNull($redacted->getAttribute('redacted_at'));
            $this->assertSame('A recent message', Message::query()->findOrFail($recent)->body);
            $this->assertSame('expired', Media::query()->findOrFail($media)->status);
        });
        Storage::disk('local')->assertMissing('media/old.jpg');
        $this->assertSame(2, $this->tenantContext()->run($this->tenant, fn () => Message::query()->count())); // the delivery record stays
    }
}
