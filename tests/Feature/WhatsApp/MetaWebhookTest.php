<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Webhooks\Jobs\ProcessWebhookChange;
use App\Domain\Webhooks\Jobs\RefreshPhoneNumber;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\QualityEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class MetaWebhookTest extends TestCase
{
    use InteractsWithWhatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
    }

    private function logRows(?string $field = null): Collection
    {
        return $this->tenantContext()->bypass(fn () => DB::table('webhook_inbound_log')->when($field, fn ($q) => $q->where('field', $field))->get());
    }

    public function test_verification_handshake(): void
    {
        $this->get('/api/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1158201444')
            ->assertOk()->assertSeeText('1158201444');

        $this->get('/api/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->assertForbidden();
    }

    public function test_invalid_signature_is_rejected_and_nothing_is_stored(): void
    {
        $body = $this->webhookBody('account_update', ['event' => 'PARTNER_REMOVED']);

        $this->postWebhook($body, 'not-the-secret')->assertStatus(401);
        $this->postWebhook($body, null)->assertStatus(401);

        $this->assertCount(0, $this->logRows());
    }

    public function test_deliveries_are_stored_once_and_processed_asynchronously(): void
    {
        Queue::fake();
        $body = $this->webhookBody('messages', ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '106540352242922']]);

        $this->postWebhook($body)->assertOk()->assertSeeText('EVENT_RECEIVED');
        $this->postWebhook($body)->assertOk(); // Meta retry of the same delivery

        $rows = $this->logRows();
        $this->assertCount(1, $rows);
        $this->assertSame('106540352242922', $rows->first()->phone_number_id);
        Queue::assertPushed(ProcessWebhookChange::class, 1);
    }

    public function test_partner_removed_disconnects_the_number(): void
    {
        $tenant = $this->createTenant();
        $number = $this->connectNumber($tenant, display: '15550783881', type: OnboardingType::Coexistence);

        $this->postWebhook($this->webhookBody('account_update', [
            'phone_number' => '15550783881', 'event' => 'PARTNER_REMOVED',
            'disconnection_info' => ['reason' => 'PRIMARY_INACTIVITY', 'initiated_by' => 'SYSTEM'],
        ]))->assertOk();

        $fresh = $this->tenantContext()->bypass(fn () => PhoneNumber::query()->find($number->id));
        $this->assertSame(PhoneNumberStatus::Disconnected, $fresh?->status);
        $this->assertSame(CoexistenceStatus::Offboarded, $fresh?->coexistence_status);

        $row = $this->logRows('account_update')->first();
        $this->assertSame('processed', $row->process_status);
        $this->assertSame($tenant->id, $row->tenant_id);

        // Only number on the WABA -> access is gone, the business token is shredded.
        $this->assertTrue($this->tenantContext()->bypass(fn () => DB::table('secrets')->where('purpose', 'meta.business_token')->whereNotNull('destroyed_at')->exists()));

        $event = $this->tenantContext()->bypass(fn () => QualityEvent::query()->first());
        $this->assertSame('account_update.partner_removed', $event?->getAttribute('event_type'));
        $this->assertSame('PRIMARY_INACTIVITY', $event?->getAttribute('new_value'));
    }

    public function test_quality_update_records_history_and_schedules_a_refresh(): void
    {
        Bus::fake([RefreshPhoneNumber::class]);
        $number = $this->connectNumber($this->createTenant(), display: '15550783881');

        $this->postWebhook($this->webhookBody('phone_number_quality_update', [
            'display_phone_number' => '15550783881', 'event' => 'UPGRADE', 'current_limit' => 'TIER_10K',
        ]))->assertOk();

        $this->assertSame('TIER_10K', $this->tenantContext()->bypass(fn () => PhoneNumber::query()->find($number->id))?->messaging_limit_tier);
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => QualityEvent::query()->where('event_type', 'quality.upgrade')->count()));
        Bus::assertDispatched(RefreshPhoneNumber::class);
    }

    public function test_messages_are_deferred_until_the_messaging_module_exists(): void
    {
        $this->connectNumber($this->createTenant());

        $this->postWebhook($this->webhookBody('messages', ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '106540352242922'], 'messages' => []]))->assertOk();
        $this->postWebhook($this->webhookBody('some_future_field', ['x' => 1]))->assertOk();

        $this->assertSame('deferred', $this->logRows('messages')->first()->process_status);
        $this->assertSame('ignored', $this->logRows('some_future_field')->first()->process_status);
    }

    public function test_history_progress_and_declined_sharing(): void
    {
        $tenant = $this->createTenant();
        $number = $this->connectNumber($tenant, type: OnboardingType::Coexistence);
        $meta = ['display_phone_number' => '15550783881', 'phone_number_id' => '106540352242922'];

        $this->postWebhook($this->webhookBody('history', ['messaging_product' => 'whatsapp', 'metadata' => $meta,
            'history' => [['metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 55], 'threads' => []]]]))->assertOk();

        $job = $this->tenantContext()->bypass(fn () => CoexistenceSyncJob::query()->where('sync_type', 'history')->first());
        $this->assertSame(55, $job?->progress);
        $this->assertSame('deferred', $this->logRows('history')->first()->process_status);

        $this->postWebhook($this->webhookBody('history', ['messaging_product' => 'whatsapp', 'metadata' => $meta,
            'history' => [['errors' => [['code' => 2593109, 'title' => 'History sync is turned off by the business from the WhatsApp Business App']]]]]))->assertOk();

        $this->assertSame('declined', $this->tenantContext()->bypass(fn () => CoexistenceSyncJob::query()->where('sync_type', 'history')->first())?->status);
        $this->assertSame(CoexistenceStatus::Synced, $this->tenantContext()->bypass(fn () => PhoneNumber::query()->find($number->id))?->coexistence_status);
    }

    public function test_unknown_waba_is_ignored_not_failed(): void
    {
        $this->postWebhook($this->webhookBody('account_update', ['event' => 'PARTNER_ADDED'], '424242424242'))->assertOk();

        $row = $this->logRows('account_update')->first();
        $this->assertSame('ignored', $row->process_status);
        $this->assertNull($row->tenant_id);
    }
}
