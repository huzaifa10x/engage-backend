<?php

declare(strict_types=1);

namespace App\Domain\Growth\Marketing;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * growth_marketing_daily (Marketing Analytics Specification §6): the cohort aggregated per first-enquiry
 * day, source bucket, campaign and number. Rebuilt for a date range (recent days every few minutes,
 * the last 31 days nightly) or entirely (first view, stage mapping changed).
 */
final class MarketingRollup
{
    /**
     * @param  ?string  $from  local date; null with $to null = all history
     */
    public function rebuild(Tenant $tenant, ?string $from = null, ?string $to = null): void
    {
        [$cohort, $bindings] = MarketingCohort::sql($tenant, MarketingCohort::mapping($tenant), $from, $to);

        DB::transaction(function () use ($tenant, $from, $to, $cohort, $bindings) {
            DB::table('growth_marketing_daily')->where('tenant_id', $tenant->id)
                ->when($from, fn ($q, $d) => $q->where('local_date', '>=', $d))
                ->when($to, fn ($q, $d) => $q->where('local_date', '<=', $d))
                ->delete();

            DB::insert(
                "INSERT INTO growth_marketing_daily (tenant_id, local_date, source_bucket, campaign_key, number_id, new_leads, contacted_30d, qualified_30d, computed_at)
                 SELECT ?::uuid, local_date, bucket, campaign_key, number_id, count(*), count(*) FILTER (WHERE contacted), count(*) FILTER (WHERE qualified), now()
                 FROM ({$cohort}) cohort
                 GROUP BY local_date, bucket, campaign_key, number_id",
                [$tenant->id, ...$bindings],
            );

            DB::statement(
                'INSERT INTO growth_marketing_state (tenant_id, computed_at) VALUES (?, now()) ON CONFLICT (tenant_id) DO UPDATE SET computed_at = now()',
                [$tenant->id],
            );
        });
    }

    /** Recent days in the workspace's time zone, today included (the whole history the first time). */
    public function rebuildRecent(Tenant $tenant, int $days): void
    {
        if (! $this->hasRun($tenant)) {
            $this->rebuild($tenant);

            return;
        }
        $today = now($tenant->timezone ?: 'UTC');
        $this->rebuild($tenant, $today->copy()->subDays($days - 1)->toDateString(), $today->toDateString());
    }

    public function hasRun(Tenant $tenant): bool
    {
        return DB::table('growth_marketing_state')->where('tenant_id', $tenant->id)->exists();
    }
}
