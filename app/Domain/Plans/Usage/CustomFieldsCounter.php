<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Crm\Models\ContactField;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Custom contact fields defined in the workspace. */
final class CustomFieldsCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::CustomFields;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => ContactField::query()->where('tenant_id', $tenant->getKey())->count());
    }
}
