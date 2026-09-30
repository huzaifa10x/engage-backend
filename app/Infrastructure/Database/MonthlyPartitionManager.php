<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates monthly RANGE(created_at) partitions ahead of time. Retention is executed by
 * DETACH + archive + DROP of whole partitions — never row-by-row DELETE.
 */
final class MonthlyPartitionManager
{
    /** @return list<string> partitions created */
    public function ensure(string $table, int $monthsAhead = 3, ?CarbonImmutable $from = null): array
    {
        $this->assertIdentifier($table);

        $start = ($from ?? CarbonImmutable::now('UTC'))->startOfMonth();
        $created = [];

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $month = $start->addMonths($i);
            $name = sprintf('%s_%s', $table, $month->format('Y_m'));

            if ($this->exists($name)) {
                continue;
            }

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $table,
                $month->toDateString(),
                $month->addMonth()->toDateString(),
            ));

            $created[] = $name;
        }

        return $created;
    }

    /** Rows that landed in the DEFAULT partition mean the scheduler fell behind — alert on this. */
    public function defaultPartitionRowCount(string $table): int
    {
        $this->assertIdentifier($table);

        return $this->exists($table.'_default')
            ? (int) DB::scalar("SELECT count(*) FROM {$table}_default")
            : 0;
    }

    private function exists(string $name): bool
    {
        return (int) DB::scalar('SELECT CASE WHEN to_regclass(CAST(? AS text)) IS NULL THEN 0 ELSE 1 END', [$name]) === 1;
    }

    private function assertIdentifier(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,48}$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table identifier [{$table}].");
        }
    }
}
