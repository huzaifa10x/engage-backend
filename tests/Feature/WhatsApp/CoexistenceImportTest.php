<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Jobs\DownloadInboundMedia;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Jobs\ImportCoexistenceBatch;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Import of WhatsApp Business app data under load control: the webhook only buffers, a
 * low-priority job imports in bounded batches, live messages are never held up, and the progress
 * shown to the user adds up exactly.
 */
final class CoexistenceImportTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const META = ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'];

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
        $this->number = $this->connectNumber($this->tenant, type: OnboardingType::Coexistence);
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['coexistence_status' => CoexistenceStatus::HistorySyncing])->save());
        $this->actingAsMember($this->owner);
    }

    private function history(int $count, int $progress, int $offset = 0, bool $withMedia = false): void
    {
        $threads = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $offset + $i;
            $customer = '9715011'.str_pad((string) ($n % 7), 5, '0', STR_PAD_LEFT);
            $threads[$customer]['id'] = $customer;
            $threads[$customer]['messages'][] = $withMedia
                ? ['from' => $customer, 'id' => "wamid.HIST{$n}", 'timestamp' => (string) (1739230000 + $n), 'type' => 'image', 'image' => ['id' => "media-{$n}", 'mime_type' => 'image/jpeg', 'sha256' => 'abc'], 'history_context' => ['status' => 'READ']]
                : ['from' => $customer, 'id' => "wamid.HIST{$n}", 'timestamp' => (string) (1739230000 + $n), 'type' => 'text', 'text' => ['body' => "Old message {$n}"], 'history_context' => ['status' => 'READ']];
        }
        $this->postWebhook($this->webhookBody('history', ['messaging_product' => 'whatsapp', 'metadata' => self::META,
            'history' => [['metadata' => ['phase' => 1, 'chunk_order' => 1, 'progress' => $progress], 'threads' => array_values($threads)]]]))->assertOk();
        $this->actingAsMember($this->owner);
    }

    private function contacts(int $count): void
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = ['type' => 'contact', 'action' => 'add', 'contact' => ['full_name' => "Customer {$i}", 'phone_number' => '9715022'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]];
        }
        $this->postWebhook($this->webhookBody('smb_app_state_sync', ['messaging_product' => 'whatsapp', 'metadata' => self::META, 'state_sync' => $items]))->assertOk();
        $this->actingAsMember($this->owner);
    }

    /** @return array<string, mixed> */
    private function sync(): array
    {
        return (array) $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->assertOk()->json('data.sync');
    }

    private function waiting(): int
    {
        return $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->count());
    }

    private function messages(): int
    {
        return $this->tenantContext()->run($this->tenant, fn () => Message::query()->count());
    }

    private function runOneBatch(): void
    {
        (new ImportCoexistenceBatch($this->tenant->id))->handle($this->tenantContext(), app(CoexistenceImport::class));
        $this->actingAsMember($this->owner);
    }

    public function test_the_webhook_only_buffers_and_one_import_chain_is_queued_per_workspace(): void
    {
        Queue::fake([ImportCoexistenceBatch::class]);
        $this->assertSame('waiting', $this->sync()['state']);

        // 30 contacts and 620 messages arrive in three webhooks, as fast as WhatsApp can send them.
        $this->contacts(30);
        $this->history(400, progress: 40);
        $this->history(220, progress: 70, offset: 400);

        // Nothing has touched the messages or contacts tables: everything is in the waiting list.
        $this->assertSame(650, $this->waiting());
        $this->assertSame(0, $this->messages());
        $this->assertSame(0, $this->tenantContext()->run($this->tenant, fn () => Contact::query()->count()));
        // Three webhooks, ONE queued chain, on the low-priority queue.
        Queue::assertPushed(ImportCoexistenceBatch::class, 1);
        Queue::assertPushedOn('maintenance', ImportCoexistenceBatch::class);

        $sync = $this->sync();
        $this->assertSame('importing', $sync['state']);
        $this->assertEquals(['received' => 650, 'imported' => 0, 'waiting' => 650, 'percent' => 0, 'whatsapp_percent' => 70], array_intersect_key($sync, array_flip(['received', 'imported', 'waiting', 'percent', 'whatsapp_percent'])));
    }

    public function test_batches_are_bounded_and_chain_until_the_list_is_empty(): void
    {
        Queue::fake([ImportCoexistenceBatch::class]);
        $this->contacts(30);
        $this->history(220, progress: 100);
        $this->assertSame(250, $this->waiting());

        // Each run imports exactly one batch (100 by default), oldest first, and queues the next one with a pause.
        $this->runOneBatch();
        $this->assertSame(150, $this->waiting());
        $sync = $this->sync();
        $this->assertEquals(['received' => 250, 'imported' => 100, 'waiting' => 150, 'percent' => 40], array_intersect_key($sync, array_flip(['received', 'imported', 'waiting', 'percent'])));
        $this->assertSame(['received' => 30, 'imported' => 30], $sync['contacts']);
        $this->assertSame(['received' => 220, 'imported' => 70], $sync['messages']);
        $this->assertSame($sync['received'], $sync['imported'] + $sync['waiting']);
        $this->assertSame('history_syncing', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'), 'not synced while records wait');
        Queue::assertPushed(ImportCoexistenceBatch::class, fn (ImportCoexistenceBatch $job) => $job->delay !== null && $job->tenantId === $this->tenant->id);

        $this->runOneBatch();
        $this->assertSame(50, $this->waiting());
        $this->runOneBatch();

        // Empty: the chain stops, the number is synced, totals are final.
        $this->assertSame(0, $this->waiting());
        $this->assertSame(220, $this->messages());
        $sync = $this->sync();
        $this->assertEquals(['state' => 'complete', 'received' => 250, 'imported' => 250, 'waiting' => 0, 'percent' => 100], array_intersect_key($sync, array_flip(['state', 'received', 'imported', 'waiting', 'percent'])));
        $this->assertSame('synced', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'));

        // The batch size is a server setting.
        config(['engage.meta.coexistence_import_batch' => 25]);
        $this->history(60, progress: 100, offset: 220);
        $this->runOneBatch();
        $this->assertSame(35, $this->waiting());
    }

    public function test_a_broken_chain_is_restarted_by_the_scheduler(): void
    {
        Queue::fake([ImportCoexistenceBatch::class]);
        $this->history(150, progress: 100);
        Queue::assertPushed(ImportCoexistenceBatch::class, 1);

        // The chain is marked as running, so the every-minute check does not start a second one …
        $this->artisan('engage:coexistence:import')->assertSuccessful();
        Queue::assertPushed(ImportCoexistenceBatch::class, 1);

        // … but if the worker died and nothing has run for five minutes, it does.
        $this->travel(6)->minutes();
        $this->artisan('engage:coexistence:import')->assertSuccessful();
        Queue::assertPushed(ImportCoexistenceBatch::class, 2);
    }

    public function test_imported_history_is_quiet_and_live_messages_are_not_held_up(): void
    {
        Queue::fake([ImportCoexistenceBatch::class, DownloadInboundMedia::class]);
        Event::fake([MessageStored::class]);

        $this->history(40, progress: 60, withMedia: true);
        $this->runOneBatch();
        $this->assertSame(40, $this->messages());

        // No realtime push per imported message, and their files are fetched on the slow queue.
        Event::assertNotDispatched(MessageStored::class);
        Queue::assertPushed(DownloadInboundMedia::class, 40);
        Queue::assertPushed(DownloadInboundMedia::class, fn (DownloadInboundMedia $job) => $job->queue === 'maintenance');

        // A customer writes while 5,000 old records are still waiting: it is in the inbox at once, with its realtime push.
        $this->history(500, progress: 80, offset: 1000);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: '971509998877')))->assertOk();
        $this->actingAsMember($this->owner);
        $this->assertSame(41, $this->messages());
        $this->assertSame(500, $this->waiting());
        Event::assertDispatched(MessageStored::class, 1);
        $this->getJson('/api/v1/conversations?unread=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_one_workspace_does_not_import_another_workspaces_records(): void
    {
        Queue::fake([ImportCoexistenceBatch::class]);
        $this->history(30, progress: 100);

        $other = $this->createTenant();
        (new ImportCoexistenceBatch($other->id))->handle($this->tenantContext(), app(CoexistenceImport::class));
        $this->assertSame(30, $this->waiting());
    }
}
