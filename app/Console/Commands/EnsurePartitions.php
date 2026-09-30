<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Database\MonthlyPartitionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class EnsurePartitions extends Command
{
    protected $signature = 'engage:partitions {--months= : Months ahead to pre-create}';

    protected $description = 'Pre-create monthly partitions for append-only high-volume tables.';

    public function handle(MonthlyPartitionManager $partitions): int
    {
        $months = (int) ($this->option('months') ?? config('engage.partitions.months_ahead', 3));

        foreach ((array) config('engage.partitions.monthly', []) as $table) {
            $created = $partitions->ensure((string) $table, $months);
            $this->components->info(sprintf('%s: %s', $table, $created ? implode(', ', $created) : 'up to date'));

            if (($stray = $partitions->defaultPartitionRowCount((string) $table)) > 0) {
                Log::warning('Rows in default partition — partition maintenance fell behind.', ['table' => $table, 'rows' => $stray]);
            }
        }

        return self::SUCCESS;
    }
}
