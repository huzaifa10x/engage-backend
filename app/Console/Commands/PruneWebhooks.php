<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Database\MonthlyPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneWebhooks extends Command
{
    protected $signature = 'engage:webhooks:prune';

    protected $description = 'Drop raw webhook partitions past retention and expire delivery dedup keys.';

    public function handle(MonthlyPartitionManager $partitions, TenantContext $context): int
    {
        // Meta retries a delivery for up to 7 days; keep dedup keys a little longer.
        $expired = $context->bypass(fn () => DB::table('webhook_deliveries')->where('received_at', '<', now()->subDays(8))->delete());

        // Customers' webhook delivery log (Developer → Logs): 30 days is enough to debug and resend.
        $context->bypass(fn () => DB::table('webhook_endpoint_deliveries')->where('created_at', '<', now()->subDays(30))->delete());

        $retention = (int) config('engage.meta.webhook_retention_days', 90);
        $dropped = $partitions->dropOlderThan('webhook_inbound_log', CarbonImmutable::now('UTC')->subDays($retention));

        $this->components->info(sprintf('Dedup keys expired: %d. Partitions dropped: %s', $expired, $dropped ? implode(', ', $dropped) : 'none'));

        return self::SUCCESS;
    }
}
