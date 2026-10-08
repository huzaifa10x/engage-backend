<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SimulateHistoryTest extends TestCase
{
    public function test_the_simulation_runs_the_real_pipeline_verifies_it_and_cleans_up(): void
    {
        $this->seed(PlanCatalogSeeder::class);

        // 240 messages in 8 chats, 50 per webhook: through the webhook log, the waiting list and the batch importer.
        $this->artisan('engage:simulate-history', ['--messages' => 240, '--chats' => 8, '--chunk' => 50, '--timeout' => 60])
            ->expectsOutputToContain('PASS: the import pipeline handled the flood.')
            ->assertSuccessful();

        $tenant = $this->tenantContext()->bypass(fn () => Tenant::query()->where('slug', 'zz-history-load-test')->firstOrFail());
        $this->assertSame(241, $this->tenantContext()->bypass(fn () => DB::table('messages')->where('tenant_id', $tenant->id)->count())); // 240 + the live probe
        $this->assertSame(9, $this->tenantContext()->bypass(fn () => DB::table('conversations')->where('tenant_id', $tenant->id)->count()));

        // A second run reuses the workspace and still verifies cleanly (new message ids, same customers).
        $this->artisan('engage:simulate-history', ['--messages' => 60, '--chats' => 8, '--chunk' => 30, '--timeout' => 60])->assertSuccessful();

        $this->artisan('engage:simulate-history', ['--cleanup' => true])->assertSuccessful();
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('messages')->where('tenant_id', $tenant->id)->count()));
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->where('tenant_id', $tenant->id)->count()));
    }

    public function test_it_refuses_to_run_on_production_without_force(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('engage:simulate-history', ['--messages' => 10])->assertFailed();
    }
}
