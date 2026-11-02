<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Change Notice CN-01 §III: an internal alert when a workspace passes the accepted-tap threshold
 * (default 100,000) in a calendar month, in the workspace's time zone. Internal only: the client sees
 * nothing and nothing is blocked; rate limits and bot filtering are separate. One alert per workspace
 * per month (Super Admin audit log + server log).
 */
final class GrowthUsageAlerts extends Command
{
    protected $signature = 'engage:growth:usage-alerts';

    protected $description = 'Flag workspaces that passed the monthly Growth Hub accepted-tap threshold (internal alert).';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $threshold = (int) config('engage.growth.accepted_taps_alert_threshold', 100_000);
        $flagged = 0;

        $context->bypass(function () use ($threshold, $audit, &$flagged) {
            $tenants = Tenant::query()->whereRaw("(settings->'growth'->>'enabled')::boolean IS TRUE")->get();
            foreach ($tenants as $tenant) {
                $now = now()->setTimezone($tenant->timezone ?: 'UTC');
                $month = $now->format('Y-m');
                $accepted = DB::table('growth_click_events')->where('tenant_id', $tenant->id)
                    ->where('status', '!=', 'filtered')
                    ->where('received_at', '>=', $now->copy()->startOfMonth()->utc())
                    ->count();
                if ($accepted < $threshold) {
                    continue;
                }

                $already = AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'growth.taps_threshold_passed')
                    ->where('meta->month', $month)->exists();
                if ($already) {
                    continue;
                }

                $audit->record('growth.taps_threshold_passed', $tenant, meta: ['month' => $month, 'accepted_taps' => $accepted, 'threshold' => $threshold], tenantId: $tenant->id);
                Log::warning('Growth Hub: workspace passed the monthly accepted-tap threshold.', ['tenant_id' => $tenant->id, 'month' => $month, 'accepted_taps' => $accepted]);
                $flagged++;
            }
        });

        $this->components->info("{$flagged} workspace(s) flagged.");

        return self::SUCCESS;
    }
}
