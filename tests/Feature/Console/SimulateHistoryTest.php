<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SimulateHistoryTest extends TestCase
{
    private function historyMessages(): int
    {
        return (int) $this->tenantContext()->bypass(fn () => DB::table('messages')->where('wamid', 'like', 'wamid.SIM\_%')->where('wamid', 'not like', 'wamid.SIM\_LIVE%')->count());
    }

    public function test_one_workspace_runs_the_real_pipeline_and_is_verified(): void
    {
        $this->seed(PlanCatalogSeeder::class);

        // 240 messages in 8 chats, 50 per webhook: through the webhook log, the waiting list and the batch importer.
        $this->artisan('engage:simulate-history', ['--messages' => 240, '--chats' => 8, '--chunk' => 50, '--timeout' => 60])
            ->expectsOutputToContain('PASS: the import pipeline handled the flood.')
            ->assertSuccessful();

        $this->assertSame(240, $this->historyMessages());
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => Tenant::query()->where('slug', 'like', 'zz-history-load-test%')->count()));

        // A second run reuses the workspace and still verifies cleanly (new message ids, same customers).
        $this->artisan('engage:simulate-history', ['--messages' => 60, '--chats' => 8, '--chunk' => 30, '--timeout' => 60])->assertSuccessful();
        $this->assertSame(300, $this->historyMessages());
    }

    public function test_several_workspaces_importing_at_once_stay_separate_and_all_finish(): void
    {
        $this->seed(PlanCatalogSeeder::class);

        $this->artisan('engage:simulate-history', ['--workspaces' => 3, '--messages' => 90, '--chats' => 6, '--chunk' => 30, '--timeout' => 60])
            ->expectsOutputToContain('PASS: the import pipeline handled the flood.')
            ->assertSuccessful();

        // Three workspaces, each with exactly its own 90 messages and 6 conversations (plus the live-probe chat).
        $tenants = $this->tenantContext()->bypass(fn () => Tenant::query()->where('slug', 'like', 'zz-history-load-test%')->orderBy('slug')->get());
        $this->assertCount(3, $tenants);
        foreach ($tenants as $tenant) {
            $this->assertSame(90, (int) $this->tenantContext()->bypass(fn () => DB::table('messages')->where('tenant_id', $tenant->id)->where('wamid', 'not like', 'wamid.SIM\_LIVE%')->count()));
            $this->assertSame('synced', $this->tenantContext()->bypass(fn () => DB::table('phone_numbers')->where('tenant_id', $tenant->id)->value('coexistence_status')));
        }

        // Cleanup removes all of them.
        $this->artisan('engage:simulate-history', ['--cleanup' => true])->assertSuccessful();
        $this->assertSame(0, (int) $this->tenantContext()->bypass(fn () => DB::table('messages')->whereIn('tenant_id', $tenants->pluck('id'))->count()));
        $this->assertSame(0, (int) $this->tenantContext()->bypass(fn () => DB::table('coexistence_sync_items')->whereIn('tenant_id', $tenants->pluck('id'))->count()));
    }

    public function test_it_refuses_to_run_on_production_without_force(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('engage:simulate-history', ['--messages' => 10])->assertFailed();
    }
}
