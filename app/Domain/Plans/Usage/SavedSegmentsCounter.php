<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Crm\Models\Segment;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Saved segments. */
final class SavedSegmentsCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::SavedSegments;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => Segment::query()->where('tenant_id', $tenant->getKey())->count());
    }
}
