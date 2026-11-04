<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Marketing\MarketingRollup;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Analytics → Marketing refresh (Marketing Analytics Specification §6): every few minutes the last
 * 2 days (new leads show up quickly), nightly the last 31 days (stage changes inside the 30-day
 * window), --all for the whole history.
 */
final class RefreshMarketingAnalyticsCommand extends Command
{
    protected $signature = 'engage:growth:marketing-refresh {--days=2 : Days to rebuild, today included} {--all : Rebuild the whole history}';

    protected $description = 'Rebuild the Analytics → Marketing daily table for workspaces with Growth Hub.';

    public function handle(TenantContext $context, MarketingRollup $rollup): int
    {
        $tenants = $context->bypass(fn () => Tenant::query()->whereRaw("(settings->'growth'->>'enabled')::boolean IS TRUE")->get());
        $done = 0;
        foreach ($tenants as $tenant) {
            try {
                $context->run($tenant, function (Tenant $t) use ($rollup, &$done) {
                    if (! GrowthAccess::full($t)) {
                        return;
                    }
                    $this->option('all') ? $rollup->rebuild($t) : $rollup->rebuildRecent($t, max(1, (int) $this->option('days')));
                    $done++;
                });
            } catch (\Throwable $e) {
                Log::warning('Marketing analytics refresh failed for a workspace.', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }
        $this->components->info("{$done} workspace(s) refreshed.");

        return self::SUCCESS;
    }
}
