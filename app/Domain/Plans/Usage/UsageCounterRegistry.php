<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Plans\FeatureKey;

final class UsageCounterRegistry
{
    /** @var array<string, UsageCounter> */
    private array $counters = [];

    /** @param iterable<UsageCounter> $counters */
    public function __construct(iterable $counters = [])
    {
        foreach ($counters as $counter) {
            $this->register($counter);
        }
    }

    public function register(UsageCounter $counter): void
    {
        $this->counters[$counter->feature()->value] = $counter;
    }

    public function for(FeatureKey $feature): ?UsageCounter
    {
        return $this->counters[$feature->value] ?? null;
    }
}
