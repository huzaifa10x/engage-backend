<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** CN-01: internal alert past the monthly accepted-tap threshold; once per month; nothing blocked. */
final class UsageAlertTest extends TestCase
{
    public function test_a_workspace_past_the_threshold_is_flagged_once_per_month(): void
    {
        config(['engage.growth.accepted_taps_alert_threshold' => 3]);
        $tenant = $this->createTenant(['settings' => ['growth' => ['enabled' => true, 'started_at' => now()->toIso8601String()]]]);
        $quiet = $this->createTenant(['settings' => ['growth' => ['enabled' => true, 'started_at' => now()->toIso8601String()]]]);

        $this->tenantContext()->bypass(function () use ($tenant, $quiet) {
            foreach ([[$tenant, 'registered'], [$tenant, 'matched'], [$tenant, 'expired'], [$tenant, 'filtered'], [$quiet, 'registered']] as [$t, $status]) {
                DB::table('growth_click_events')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $t->id, 'site_id' => (string) Str::uuid7(), 'client_event_id' => (string) Str::uuid7(),
                    'mapping_id' => (string) Str::uuid7(), 'payload_hash' => hash('sha256', (string) Str::uuid7()), 'event_time' => now(), 'received_at' => now(),
                    'campaign_params_origin' => 'current_page', 'collection_mode' => 'click_only', 'status' => $status,
                ]);
            }
        });

        $this->artisan('engage:growth:usage-alerts')->assertSuccessful();
        $this->artisan('engage:growth:usage-alerts')->assertSuccessful();

        $alerts = $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'growth.taps_threshold_passed')->get());
        $this->assertCount(1, $alerts);
        $this->assertSame($tenant->id, $alerts[0]->tenant_id);
        $this->assertSame(3, $alerts[0]->meta['accepted_taps']);
    }
}
