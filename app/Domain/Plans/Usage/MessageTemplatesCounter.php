<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Templates that exist on Meta for this workspace (deleted ones free their slot). */
final class MessageTemplatesCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::MessageTemplates;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => MessageTemplate::query()->where('tenant_id', $tenant->getKey())->count());
    }
}
