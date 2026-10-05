<?php

declare(strict_types=1);

namespace Tests\Feature\Templates;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Templates\Events\TemplatesChanged;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class TemplatesTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const WABA = '102290129340398';

    private const LIST = 'graph.facebook.com/v25.0/102290129340398/message_templates*';

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

    /** @return array<string, mixed> */
    private function node(string $name, string $status = 'APPROVED', string $id = '900001', string $body = 'Hi {{1}}, your order {{2}} is ready.'): array
    {
        return [
            'id' => $id, 'name' => $name, 'language' => 'en_US', 'status' => $status, 'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => $body],
                ['type' => 'FOOTER', 'text' => 'Nova Fitness'],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $nodes */
    private function fakeList(array $nodes): void
    {
        Http::fake([self::LIST => Http::response(['data' => $nodes])]);
    }

    private function sync(): void
    {
        $this->postJson('/api/v1/templates/sync')->assertOk();
    }

    public function test_sync_mirrors_meta_including_statuses_edits_and_deletions(): void
    {
        $this->actingAsMember($this->owner);
        $this->fakeList([$this->node('order_ready'), $this->node('promo_june', 'PENDING', '900002')]);

        $this->postJson('/api/v1/templates/sync')->assertOk()->assertJsonPath('data.synced', 2)->assertJsonPath('data.removed', 0);

        $this->getJson('/api/v1/templates')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'order_ready')->assertJsonPath('data.0.status', 'APPROVED')->assertJsonPath('data.0.sendable', true)
            ->assertJsonPath('data.0.variables.body', ['1', '2'])
            ->assertJsonPath('data.1.status', 'PENDING')->assertJsonPath('data.1.sendable', false);

        // Edited and rejected on Meta, and the other template deleted there.
        Http::swap(new Factory);
        $this->fakeList([$this->node('order_ready', 'REJECTED', '900001', 'Hello {{1}}!') + ['rejected_reason' => 'INVALID_FORMAT']]);

        $this->postJson('/api/v1/templates/sync')->assertOk()->assertJsonPath('data.synced', 1)->assertJsonPath('data.removed', 1);

        $this->getJson('/api/v1/templates')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'REJECTED')->assertJsonPath('data.0.rejected_reason', 'INVALID_FORMAT')
            ->assertJsonPath('data.0.variables.body', ['1']);
    }

    public function test_status_webhook_updates_a_template_in_real_time(): void
    {
        $this->actingAsMember($this->owner);
        $this->fakeList([$this->node('promo_june', 'PENDING', '900002')]);
        $this->sync();

        $this->postWebhook($this->webhookBody('message_template_status_update', [
            'event' => 'APPROVED', 'message_template_id' => 900002, 'message_template_name' => 'promo_june', 'message_template_language' => 'en_US', 'reason' => 'NONE',
        ]))->assertOk();
        $this->getJson('/api/v1/templates')->assertJsonPath('data.0.status', 'APPROVED');

        $this->postWebhook($this->webhookBody('message_template_status_update', [
            'event' => 'PAUSED', 'message_template_id' => 900002, 'message_template_name' => 'promo_june', 'message_template_language' => 'en_US',
        ]))->assertOk();
        $this->postWebhook($this->webhookBody('message_template_quality_update', [
            'previous_quality_score' => 'GREEN', 'new_quality_score' => 'RED', 'message_template_id' => 900002, 'message_template_name' => 'promo_june', 'message_template_language' => 'en_US',
        ]))->assertOk();

        $this->getJson('/api/v1/templates')->assertJsonPath('data.0.status', 'PAUSED')->assertJsonPath('data.0.quality_score', 'RED')
            ->assertJsonPath('data.0.sendable', false);
    }

    public function test_owner_creates_and_submits_a_template_to_meta(): void
    {
        $this->actingAsMember($this->owner);
        Http::fake([self::LIST => Http::response(['id' => '777001', 'status' => 'PENDING', 'category' => 'UTILITY'])]);

        $this->postJson('/api/v1/templates', [
            'waba_account_id' => $this->number->waba_account_id, 'name' => 'order_update', 'language' => 'en_US', 'category' => 'UTILITY',
            'header' => ['text' => 'Order update'],
            'body' => 'Hi {{1}}, your order {{2}} has shipped.', 'body_examples' => ['Sara', '#1042'],
            'footer' => 'Reply STOP to opt out',
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Thanks'], ['type' => 'URL', 'text' => 'Track', 'url' => 'https://example.com/t/{{1}}', 'example' => 'https://example.com/t/1042']],
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.meta_template_id', '777001');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/'.self::WABA.'/message_templates')
            && $r['name'] === 'order_update' && $r['category'] === 'UTILITY'
            && $r['components'][1]['type'] === 'BODY' && $r['components'][1]['example']['body_text'] === [['Sara', '#1042']]
            && $r['components'][3]['buttons'][1]['example'] === ['https://example.com/t/1042']);

        // Same name + language again is refused before calling Meta.
        $this->postJson('/api/v1/templates', [
            'waba_account_id' => $this->number->waba_account_id, 'name' => 'order_update', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => 'Hello',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_variables_need_examples_and_meta_rejections_are_explained(): void
    {
        $this->actingAsMember($this->owner);

        $this->postJson('/api/v1/templates', [
            'waba_account_id' => $this->number->waba_account_id, 'name' => 'no_examples', 'language' => 'en', 'category' => 'MARKETING', 'body' => 'Hi {{1}}, welcome.',
        ])->assertStatus(422)->assertJsonPath('error.details.fields.body_examples.0', 'Add an example value for every variable so Meta can review the template.');

        Http::fake([self::LIST => Http::response(['error' => [
            'message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2388024, 'error_user_msg' => 'There is already English (US) content for this template.', 'fbtrace_id' => 'T1',
        ]], 400)]);

        $response = $this->postJson('/api/v1/templates', [
            'waba_account_id' => $this->number->waba_account_id, 'name' => 'welcome', 'language' => 'en_US', 'category' => 'MARKETING', 'body' => 'Welcome to Nova Fitness.',
        ])->assertStatus(422)->assertJsonPath('error.code', 'meta_api_error');
        $this->assertStringContainsString('There is already English (US) content', (string) $response->json('error.message'));

        $this->assertSame(0, $this->tenantContext()->bypass(fn () => MessageTemplate::query()->count()));
    }

    public function test_a_media_header_uploads_the_sample_to_meta_and_submits_its_handle(): void
    {
        Storage::fake('local');
        $this->actingAsMember($this->owner);
        Http::fake([
            // Real session ids carry their own signature in a query string.
            'graph.facebook.com/v25.0/1234567890/uploads*' => Http::response(['id' => 'upload:MTphdHRhY2htZW50?sig=ARZqkGCA_uQMxC8nHKI']),
            'graph.facebook.com/v25.0/upload:MTphdHRhY2htZW50*' => fn (Request $r) => str_contains($r->url(), 'sig=ARZqkGCA_uQMxC8nHKI')
                ? Http::response(['h' => '4:aGVhZGVy:handle'])
                : Http::response(['debug_info' => ['retriable' => false, 'type' => 'InvalidSignature', 'message' => 'Signature missing']], 400),
            self::LIST => Http::response(['id' => '777002', 'status' => 'PENDING', 'category' => 'MARKETING']),
        ]);

        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $mediaId = $this->post('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('offer.png', $png)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $form = ['waba_account_id' => $this->number->waba_account_id, 'name' => 'summer_offer', 'language' => 'en', 'category' => 'MARKETING', 'body' => 'Our summer offer is here.'];

        // A media header without a file is refused before anything is sent to Meta.
        $this->postJson('/api/v1/templates', $form + ['header' => ['format' => 'IMAGE']])
            ->assertStatus(422)->assertJsonPath('error.details.fields', fn (array $fields) => isset($fields['header.media_id']));
        // ... and so is the wrong kind of file for the chosen header.
        $this->postJson('/api/v1/templates', $form + ['header' => ['format' => 'VIDEO', 'media_id' => $mediaId]])->assertStatus(422);

        $this->postJson('/api/v1/templates', $form + ['header' => ['format' => 'IMAGE', 'media_id' => $mediaId]])
            ->assertCreated()->assertJsonPath('data.variables.header_format', 'IMAGE');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/1234567890/uploads') && str_contains($r->url(), 'file_type=image%2Fpng') && str_contains($r->url(), 'file_length='.strlen($png)));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/upload:MTphdHRhY2htZW50?sig=ARZqkGCA_uQMxC8nHKI&appsecret_proof=') && $r->hasHeader('Authorization', 'OAuth EAA-existing-token') && $r->hasHeader('file_offset', '0') && $r->body() === $png);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/message_templates')
            && $r['components'][0] === ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['4:aGVhZGVy:handle']]]);
    }

    public function test_template_changes_are_broadcast_to_the_workspace_in_real_time(): void
    {
        $this->actingAsMember($this->owner);
        $this->fakeList([$this->node('promo_june', 'PENDING', '900002')]);
        $this->sync();

        Event::fake([TemplatesChanged::class]);

        // A sync that changes nothing stays silent …
        $this->sync();
        Event::assertNotDispatched(TemplatesChanged::class);

        // … Meta's approval webhook is announced on the workspace's private channel.
        $this->postWebhook($this->webhookBody('message_template_status_update', [
            'event' => 'APPROVED', 'message_template_id' => 900002, 'message_template_name' => 'promo_june', 'message_template_language' => 'en_US',
        ]))->assertOk();

        Event::assertDispatched(TemplatesChanged::class, fn (TemplatesChanged $e) => $e->status === 'APPROVED'
            && $e->broadcastOn()[0]->name === "private-tenant.{$this->tenant->id}.templates" && $e->broadcastAs() === 'templates.changed');
    }

    public function test_deleting_a_template_removes_it_on_meta_first(): void
    {
        $this->actingAsMember($this->owner);
        $this->fakeList([$this->node('order_ready')]);
        $this->sync();
        $id = $this->getJson('/api/v1/templates')->json('data.0.id');

        Http::swap(new Factory);
        Http::fake([self::LIST => Http::response(['success' => true])]);

        $this->deleteJson("/api/v1/templates/{$id}")->assertNoContent();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'name=order_ready') && str_contains($r->url(), 'hsm_id=900001'));
        $this->getJson('/api/v1/templates')->assertJsonCount(0, 'data');
    }

    public function test_templates_are_isolated_per_workspace_and_agents_cannot_create(): void
    {
        $this->actingAsMember($this->owner);
        $this->fakeList([$this->node('order_ready')]);
        $this->sync();
        $id = $this->getJson('/api/v1/templates')->json('data.0.id');

        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/templates')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/templates/{$id}")->assertNotFound();
        $this->deleteJson("/api/v1/templates/{$id}")->assertNotFound();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/templates')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/templates', ['waba_account_id' => $this->number->waba_account_id, 'name' => 'x', 'language' => 'en', 'category' => 'UTILITY', 'body' => 'Hi'])->assertForbidden();
    }

    public function test_sending_an_approved_template_fills_variables_and_stores_the_text(): void
    {
        $this->actingAsMember($this->owner);
        $this->fakeList([$this->node('order_ready'), $this->node('promo_june', 'PENDING', '900002', 'Sale on now')]);
        $this->sync();
        Http::fake([self::SEND => fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '971501234567', 'wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.TPL'.bin2hex(random_bytes(4))]]])]);

        // Outside any window: a new contact by phone number, approved template with variables.
        $this->postJson('/api/v1/messages', [
            'phone_number_id' => $this->number->id, 'to' => '+971 50 123 4567', 'type' => 'template',
            'template' => ['name' => 'order_ready', 'language' => 'en_US', 'variables' => ['body' => ['Sara', '#1042']]],
        ])->assertStatus(202)->assertJsonPath('data.body', 'Hi Sara, your order #1042 is ready.')->assertJsonPath('data.template.rendered.footer', 'Nova Fitness');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && $r['type'] === 'template'
            && $r['template']['name'] === 'order_ready' && $r['template']['language']['code'] === 'en_US'
            && $r['template']['components'][0]['type'] === 'body'
            && array_column($r['template']['components'][0]['parameters'], 'text') === ['Sara', '#1042']);

        // The same contact is reused, not recreated.
        $this->postJson('/api/v1/messages', [
            'phone_number_id' => $this->number->id, 'to' => '00971501234567', 'type' => 'template',
            'template' => ['name' => 'order_ready', 'language' => 'en_US', 'variables' => ['body' => ['Sara', '#1043']]],
        ])->assertStatus(202);
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => Contact::query()->count()));
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->count()));

        // Missing variables, a template still in review and an unknown template are refused before Meta.
        foreach ([
            ['name' => 'order_ready', 'language' => 'en_US', 'variables' => ['body' => ['Sara']]],
            ['name' => 'promo_june', 'language' => 'en_US'],
            ['name' => 'does_not_exist', 'language' => 'en_US'],
        ] as $template) {
            $this->postJson('/api/v1/messages', ['phone_number_id' => $this->number->id, 'to' => '+971501234567', 'type' => 'template', 'template' => $template])
                ->assertStatus(422)->assertJsonPath('error.code', 'template_unavailable');
        }

        $this->assertSame(2, $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('type', 'template')->count()));
    }
}
