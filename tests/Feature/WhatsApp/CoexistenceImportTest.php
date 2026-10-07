<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * The paced import of WhatsApp Business app data: nothing is imported faster than the hourly
 * allowance per workspace, and the progress shown to the user adds up exactly.
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

    /** One history webhook with $count messages spread over a few chats. */
    private function history(int $count, int $progress, int $offset = 0): void
    {
        $threads = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $offset + $i;
            $customer = '9715011'.str_pad((string) ($n % 7), 5, '0', STR_PAD_LEFT);
            $threads[$customer]['id'] = $customer;
            $threads[$customer]['messages'][] = ['from' => $customer, 'id' => "wamid.HIST{$n}", 'timestamp' => (string) (1739230000 + $n), 'type' => 'text', 'text' => ['body' => "Old message {$n}"], 'history_context' => ['status' => 'READ']];
        }
        $this->postWebhook($this->webhookBody('history', ['messaging_product' => 'whatsapp', 'metadata' => self::META,
            'history' => [['metadata' => ['phase' => 1, 'chunk_order' => 1, 'progress' => $progress], 'threads' => array_values($threads)]]]))->assertOk();
    }

    private function contacts(int $count): void
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = ['type' => 'contact', 'action' => 'add', 'contact' => ['full_name' => "Customer {$i}", 'phone_number' => '9715022'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]];
        }
        $this->postWebhook($this->webhookBody('smb_app_state_sync', ['messaging_product' => 'whatsapp', 'metadata' => self::META, 'state_sync' => $items]))->assertOk();
    }

    /** @return array<string, mixed> */
    private function sync(): array
    {
        return (array) $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->assertOk()->json('data.sync');
    }

    /** Runs the every-minute importer for $minutes simulated minutes. */
    private function runFor(int $minutes): void
    {
        for ($i = 0; $i < $minutes; $i++) {
            $this->artisan('engage:coexistence:import')->assertSuccessful();
            $this->travel(1)->minutes();
        }
        $this->actingAsMember($this->owner);
    }

    private function imported(): int
    {
        return $this->tenantContext()->run($this->tenant, fn () => Message::query()->count() + Contact::query()->where('source', 'app_sync')->count());
    }

    public function test_received_records_wait_and_progress_adds_up_exactly(): void
    {
        $this->assertSame('waiting', $this->sync()['state']);

        $this->contacts(30);
        $this->history(120, progress: 40);

        // Everything is counted as received; nothing is imported yet.
        $sync = $this->sync();
        $this->assertSame('importing', $sync['state']);
        $this->assertSame(['received' => 150, 'imported' => 0, 'remaining' => 150, 'percent' => 0], array_intersect_key($sync, array_flip(['received', 'imported', 'remaining', 'percent'])));
        $this->assertSame(['received' => 30, 'imported' => 0, 'remaining' => 30], $sync['contacts']);
        $this->assertSame(['received' => 120, 'imported' => 0, 'remaining' => 120], $sync['messages']);
        $this->assertSame(40, $sync['whatsapp_progress']);
        $this->assertFalse($sync['whatsapp_finished']);
        $this->assertSame(200, $sync['per_hour']);
        $this->assertSame(45, $sync['minutes_left']); // 150 records at 200 an hour
        $this->assertSame(0, $this->imported());

        // Ten minutes later: 4 a minute, in arrival order (contacts came first).
        $this->runFor(10);
        $sync = $this->sync();
        $this->assertSame(40, $sync['imported']);
        $this->assertSame(110, $sync['remaining']);
        $this->assertSame(26, $sync['percent']);
        $this->assertSame(30, $sync['contacts']['imported']);
        $this->assertSame(10, $sync['messages']['imported']);
        $this->assertSame(40, $this->imported());
        $this->assertSame($sync['received'], $sync['imported'] + $sync['remaining']);
        $this->assertNotNull($sync['last_imported_at']);

        // WhatsApp sends the rest; the number is not "synced" until the last record is in.
        $this->history(50, progress: 100, offset: 120);
        $sync = $this->sync();
        $this->assertTrue($sync['whatsapp_finished']);
        $this->assertSame(200, $sync['received']);
        $this->assertSame('importing', $sync['state']);
        $this->assertSame('history_syncing', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'));

        $this->runFor(60);
        $sync = $this->sync();
        $this->assertSame(['state' => 'complete', 'received' => 200, 'imported' => 200, 'remaining' => 0, 'percent' => 100, 'minutes_left' => 0], array_intersect_key($sync, array_flip(['state', 'received', 'imported', 'remaining', 'percent', 'minutes_left'])));
        $this->assertSame('synced', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'));
        $this->assertSame(200, $this->imported());
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->count()));
    }

    public function test_never_more_than_200_records_in_any_hour_per_workspace(): void
    {
        $this->history(700, progress: 100);

        // However often the importer runs inside one hour, the workspace's allowance is the ceiling.
        for ($i = 0; $i < 59; $i++) {
            $this->artisan('engage:coexistence:import');
            $this->artisan('engage:coexistence:import'); // a second run in the same minute changes nothing about the hourly total
            $this->travel(1)->minutes();
        }
        $this->assertSame(200, $this->imported());
        $this->artisan('engage:coexistence:import');
        $this->assertSame(200, $this->imported());

        // The next hour brings the next 200.
        $this->travel(2)->minutes();
        $this->runFor(60);
        $this->assertSame(400, $this->imported());
        $sync = $this->sync();
        $this->assertSame(300, $sync['remaining']);
        $this->assertSame(90, $sync['minutes_left']);

        // Another workspace has its own allowance and its own list.
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->tenantContext()->run($other, function () use ($other): void {
            $this->assertSame(0, app(CoexistenceImport::class)->importNext($other));
        });
    }

    public function test_live_messages_are_never_held_back_by_the_import(): void
    {
        $this->history(700, progress: 60);

        // A customer writes while the import crawls along: it is in the inbox at once.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: '971509998877')))->assertOk();
        $this->actingAsMember($this->owner);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => Message::query()->count()));
    }

    public function test_a_record_that_cannot_be_read_is_skipped_not_stuck(): void
    {
        $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->insert([
            'tenant_id' => $this->tenant->id, 'phone_number_id' => $this->number->id, 'kind' => 'message', 'thread_user' => '971501112233',
            'payload' => json_encode(['type' => 'text']), 'created_at' => now(), // no id, no sender: unusable
        ]));
        $this->history(3, progress: 100);
        $this->runFor(2);

        // The list is empty and the good records behind the bad one are all in.
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->count()));
        $this->assertSame(3, $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('wamid', 'like', 'wamid.HIST%')->count()));
    }
}
