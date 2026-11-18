<?php

declare(strict_types=1);

namespace Tests\Feature\Data;

use App\Application\Compliance\RetentionRunner;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Data\Models\DataExport;
use App\Domain\Developer\Models\ApiKey;
use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Notifications\WorkspaceDeletionNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/** Batch 6, item 14: exports, message retention, deleting a contact, deleting the workspace. */
final class DataExportRetentionDeletionTest extends TestCase
{
    use BuildsChatbots;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->bootChatbots();
        $this->inTenant(fn () => $this->tenant->forceFill(['timezone' => 'Asia/Dubai'])->save());
        $this->actingAsMember($this->owner);
    }

    private function audited(string $action): ?AuditLog
    {
        return $this->inTenant(fn () => AuditLog::query()->where('action', $action)->latest('created_at')->orderByDesc('id')->first());
    }

    public function test_the_owner_exports_csv_files_that_open_in_a_spreadsheet_and_others_cannot(): void
    {
        $this->says('Hello, is the SUV free on Friday? =SUM(A1)');
        $kinds = array_column($this->getJson('/api/v1/data/exports')->assertOk()->json('meta.kinds'), 'key');
        $this->assertSame(['contacts', 'enquiries', 'timeline', 'messages', 'payments', 'audit_log'], $kinds);

        foreach ($kinds as $kind) {
            $this->postJson('/api/v1/data/exports', ['kind' => $kind])->assertStatus(202);
        }
        $exports = collect($this->getJson('/api/v1/data/exports')->assertOk()->json('data'))->keyBy('kind');
        $this->assertSame(array_fill(0, count($kinds), 'ready'), array_map(fn ($k) => $exports[$k]['status'], $kinds));

        $csv = $this->get("/api/v1/data/exports/{$exports['messages']['id']}/download")->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel reads it as UTF-8');
        $rows = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
        $this->assertSame('id', $rows[0][0]);
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame(['+971501234567', 'inbound'], [$row['contact_phone'], $row['direction']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+04:00$/', $row['occurred_at'], 'ISO 8601 in the workspace time zone');
        $this->assertStringContainsString('=SUM(A1)', $row['text']);

        $contacts = array_map('str_getcsv', explode("\n", trim(substr($this->get("/api/v1/data/exports/{$exports['contacts']['id']}/download")->streamedContent(), 3))));
        $this->assertSame('+971501234567', array_combine($contacts[0], $contacts[1])['phone']);
        $this->assertSame('contacts', $this->audited('data.export_downloaded')?->meta['kind']);

        // An Admin may not export everything or delete the workspace.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $this->getJson('/api/v1/data/exports')->assertForbidden();
        $this->postJson('/api/v1/data/exports', ['kind' => 'contacts'])->assertForbidden();
        $this->get("/api/v1/data/exports/{$exports['contacts']['id']}/download")->assertForbidden();
        $this->postJson('/api/v1/tenant/deletion', ['confirm' => $this->tenant->name])->assertForbidden();
    }

    public function test_retention_removes_old_message_text_but_the_timeline_keeps_the_event_marked_removed(): void
    {
        $this->says('My passport number is in the next message');
        $this->putJson('/api/v1/compliance/settings', ['retention_enabled' => true, 'message_retention_days' => 90])->assertOk();
        $contact = $this->contact();
        $this->inTenant(fn () => DB::table('messages')->update(['created_at' => now()->subDays(91)]));

        $this->inTenant(fn () => app(RetentionRunner::class)->apply($this->tenant->refresh()));

        $this->assertNull($this->inTenant(fn () => DB::table('messages')->value('body')));
        $line = collect($this->getJson("/api/v1/contacts/{$contact->id}/timeline?group=messages")->assertOk()->json('data'))->firstWhere('type', 'message.received');
        $this->assertTrue($line['data']['removed']);
    }

    public function test_deleting_a_contact_removes_their_data_and_keeps_payments_without_their_details(): void
    {
        $this->says('Hi, I would like to rent a car');
        $contact = $this->contact();
        $integration = (string) Str::uuid7();
        $this->inTenant(fn () => DB::table('integrations')->insert(['id' => $integration, 'tenant_id' => $this->tenant->id, 'provider' => 'woocommerce', 'public_id' => 'pub1', 'name' => 'Shop', 'external_id' => 'shop.example.com', 'created_at' => now(), 'updated_at' => now()]));
        $this->inTenant(fn () => DB::table('commerce_orders')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'integration_id' => $integration, 'store_name' => 'Shop', 'external_id' => '1', 'number' => '1001', 'status' => 'completed',
            'currency' => 'AED', 'total' => 450, 'paid' => true, 'billing_phone' => '971501234567', 'billing_name' => 'Sara Ahmed', 'contact_id' => $contact->id,
            'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->deleteJson("/api/v1/contacts/{$contact->id}")->assertNoContent();

        $this->inTenant(function () use ($contact): void {
            foreach (['messages', 'conversations', 'timeline_events', 'enquiries'] as $table) {
                $this->assertSame(0, DB::table($table)->where('contact_id', $contact->id)->count(), $table);
            }
            $this->assertNull(Contact::withTrashed()->find($contact->id));
            $order = DB::table('commerce_orders')->sole();
            $this->assertSame([null, null, null, '450.00'], [$order->contact_id, $order->billing_phone, $order->billing_name, number_format((float) $order->total, 2, '.', '')]);
        });
        $this->assertSame(1, $this->audited('contact.erased')?->meta['payments_kept']);
    }

    public function test_an_opted_out_contact_keeps_only_the_number_and_consent_so_they_are_never_messaged_again(): void
    {
        $this->says('STOP');
        $contact = $this->contact();
        $this->assertSame('opted_out', $contact->consent_state->value);
        $this->deleteJson("/api/v1/contacts/{$contact->id}")->assertNoContent();

        $kept = $this->inTenant(fn () => Contact::withTrashed()->find($contact->id));
        $this->assertNotNull($kept?->deleted_at);
        $this->assertSame(['971501234567', null, 'opted_out'], [$kept->wa_id, $kept->profile_name, $kept->consent_state->value]);
        $this->assertSame(0, $this->inTenant(fn () => DB::table('messages')->count()));
    }

    public function test_the_owner_deletes_the_workspace_after_30_days_unless_cancelled_and_our_team_is_told(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/developer/api-keys', ['name' => 'CRM', 'scopes' => ['contacts:read']])->assertCreated();
        $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://hooks.example.com/x', 'events' => ['contact.created']])->assertCreated();

        $this->postJson('/api/v1/tenant/deletion', ['confirm' => 'Not the name'])->assertUnprocessable();
        $scheduled = $this->postJson('/api/v1/tenant/deletion', ['confirm' => $this->tenant->name])->assertStatus(202)->json('data');
        $this->assertSame(30, $scheduled['waiting_days']);
        $this->assertSame(now()->addDays(30)->toDateString(), substr($scheduled['scheduled_for'], 0, 10));
        $this->inTenant(function (): void {
            $this->assertNotNull(ApiKey::query()->sole()->revoked_at);
            $this->assertSame('paused', WebhookEndpoint::query()->sole()->status);
        });
        Notification::assertSentOnDemand(WorkspaceDeletionNotification::class, fn ($n, $channels, $notifiable) => $n->what === 'requested' && $notifiable->routes['mail'] === config('engage.sales_email'));
        $this->assertNotNull($this->audited('tenant.deletion_requested'));

        // Cancelled: webhooks back on, the team told.
        $this->deleteJson('/api/v1/tenant/deletion')->assertNoContent();
        $this->assertSame('active', $this->inTenant(fn () => WebhookEndpoint::query()->sole()->status));
        Notification::assertSentOnDemand(WorkspaceDeletionNotification::class, fn ($n) => $n->what === 'cancelled');
        $this->assertNull($this->getJson('/api/v1/tenant/deletion')->json('data.scheduled_for'));

        // Scheduled again; nothing happens before the day, then it is closed and erased.
        $this->postJson('/api/v1/tenant/deletion', ['confirm' => $this->tenant->name])->assertStatus(202);
        $this->artisan('engage:workspace-deletions')->assertSuccessful();
        $this->assertSame(TenantStatus::Active, $this->tenantContext()->bypass(fn () => Tenant::query()->find($this->tenant->id))?->status);
        $this->travel(31)->days();
        $this->artisan('engage:workspace-deletions')->assertSuccessful();
        $this->assertNull($this->tenantContext()->bypass(fn () => Tenant::query()->find($this->tenant->id)), 'erased after the waiting period');
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('contacts')->where('tenant_id', $this->tenant->id)->count()));
        $this->assertTrue($this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'tenant.deletion_started')->exists()));
        Notification::assertSentOnDemand(WorkspaceDeletionNotification::class, fn ($n) => $n->what === 'deleted');
    }

    public function test_expired_export_files_are_removed(): void
    {
        $id = $this->postJson('/api/v1/data/exports', ['kind' => 'contacts'])->assertStatus(202)->json('data.id');
        $path = $this->inTenant(fn () => DataExport::query()->findOrFail($id)->path);
        Storage::disk('local')->assertExists((string) $path);
        $this->travel(8)->days();
        $this->artisan('engage:workspace-deletions')->assertSuccessful();
        Storage::disk('local')->assertMissing((string) $path);
    }
}
