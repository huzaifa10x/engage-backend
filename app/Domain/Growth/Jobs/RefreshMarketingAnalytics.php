<?php

declare(strict_types=1);

namespace App\Domain\Growth\Jobs;

use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Marketing\MarketingRollup;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Refreshes the workspace's Analytics → Marketing table after a stage change or new evidence: the
 * last 31 days (a stage change only counts within 30 days of the first enquiry). Unique per
 * workspace for a minute, so a burst of changes is one refresh. Runs in the workspace's context.
 */
final class RefreshMarketingAnalytics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $uniqueFor = 60;

    public function __construct(public readonly string $tenantId, public readonly int $days = 31)
    {
        $this->onQueue(QueueName::Default->value);
        $this->delay(now()->addSeconds(20));
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->days;
    }

    public function handle(TenantContext $context, MarketingRollup $rollup): void
    {
        $tenant = $context->tenantOrNull();
        if ($tenant === null || $tenant->id !== $this->tenantId || ! GrowthAccess::full($tenant)) {
            return;
        }
        $rollup->rebuildRecent($tenant, $this->days);
    }
}
