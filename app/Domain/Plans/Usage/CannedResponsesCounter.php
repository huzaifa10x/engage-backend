<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Messaging\Models\CannedResponse;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Canned responses saved in the workspace. */
final class CannedResponsesCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::CannedResponses;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => CannedResponse::query()->where('tenant_id', $tenant->getKey())->count());
    }
}
