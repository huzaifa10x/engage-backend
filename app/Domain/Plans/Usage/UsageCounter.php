<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Current consumption of a limit feature. Must be cheap: count small tables directly, read
 * usage_rollups / counters for high-volume metrics (contacts, messages) — never COUNT(*) a
 * large production table on the request path.
 */
interface UsageCounter
{
    public function feature(): FeatureKey;

    public function current(Tenant $tenant): int;
}
