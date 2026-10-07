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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Import of WhatsApp Business app data: records are imported the moment they arrive (no rate
 * limit), and the progress shown to the user reflects what has really been imported.
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

    public function test_records_are_imported_as_they_arrive_with_no_rate_limit_and_progress_follows(): void
    {
        $this->assertSame('waiting', $this->sync()['state']);

        // 30 contacts and 600 messages arrive in one go: all of them are in immediately.
        $this->contacts(30);
        $this->history(600, progress: 40);

        $this->assertSame(600, $this->tenantContext()->run($this->tenant, fn () => Message::query()->count()));
        $this->assertSame(30, $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('source', 'app_sync')->count()));
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->count()), 'nothing waits in a list');

        $sync = $this->sync();
        $this->assertSame('importing', $sync['state']);
        $this->assertSame(40, $sync['percent']);          // WhatsApp has sent 40% of the history
        $this->assertFalse($sync['whatsapp_finished']);
        $this->assertSame(630, $sync['imported']);
        $this->assertSame(30, $sync['contacts']);
        $this->assertSame(600, $sync['messages']);
        $this->assertSame(0, $sync['waiting']);
        $this->assertNotNull($sync['last_imported_at']);
        $this->assertArrayNotHasKey('per_hour', $sync);
        $this->assertSame('history_syncing', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'));

        // WhatsApp sends the rest: complete at once.
        $this->history(50, progress: 100, offset: 600);
        $sync = $this->sync();
        $this->assertEquals(['state' => 'complete', 'percent' => 100, 'imported' => 680, 'messages' => 650], array_intersect_key($sync, array_flip(['state', 'percent', 'imported', 'messages'])));
        $this->assertSame('synced', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'));
    }

    public function test_records_left_waiting_by_the_earlier_paced_version_are_all_imported_in_one_run(): void
    {
        // What the previous version left behind: a job that had received 500 records and imported 120.
        $this->history(1, progress: 100);
        $this->tenantContext()->bypass(function (): void {
            DB::table('coexistence_sync_jobs')->where('phone_number_id', $this->number->id)->where('sync_type', 'history')->update(['records_received' => 500, 'records_imported' => 120, 'status' => 'in_progress']);
            DB::table('phone_numbers')->where('id', $this->number->id)->update(['coexistence_status' => 'history_syncing']);
            $rows = [];
            for ($i = 0; $i < 380; $i++) {
                $rows[] = ['tenant_id' => $this->tenant->id, 'phone_number_id' => $this->number->id, 'kind' => 'message', 'thread_user' => '971501112233',
                    'payload' => json_encode(['from' => '971501112233', 'id' => "wamid.OLD{$i}", 'timestamp' => (string) (1739000000 + $i), 'type' => 'text', 'text' => ['body' => "Queued {$i}"]]), 'created_at' => now()];
            }
            DB::table('coexistence_sync_items')->insert($rows);
        });
        $this->assertSame(380, $this->sync()['waiting']);
        $this->assertSame('importing', $this->sync()['state']);

        // One run, no hourly limit: the whole list is imported.
        $this->artisan('engage:coexistence:import')->assertSuccessful();
        $this->actingAsMember($this->owner);

        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->count()));
        $this->assertSame(380, $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('wamid', 'like', 'wamid.OLD%')->count()));
        $sync = $this->sync();
        $this->assertEquals(['state' => 'complete', 'waiting' => 0, 'percent' => 100], array_intersect_key($sync, array_flip(['state', 'waiting', 'percent'])));
        $this->assertSame('synced', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'));

        // With nothing left, the command does nothing.
        $this->artisan('engage:coexistence:import')->assertSuccessful();
    }

    public function test_live_messages_arrive_alongside_an_import(): void
    {
        $this->history(200, progress: 60);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: '971509998877')))->assertOk();
        $this->actingAsMember($this->owner);

        $this->assertSame(201, $this->tenantContext()->run($this->tenant, fn () => Message::query()->count()));
    }
}
