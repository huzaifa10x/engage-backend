<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Crm\Models\ContactTag;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Tags defined in the workspace. */
final class TagsCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::Tags;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => ContactTag::query()->where('tenant_id', $tenant->getKey())->count());
    }
}
