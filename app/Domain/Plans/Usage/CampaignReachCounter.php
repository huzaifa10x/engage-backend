<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Campaign messages handed to WhatsApp in the current calendar month (the plan's reach meter). */
final class CampaignReachCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::CampaignReachMonthly;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => CampaignRecipient::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('status', ['pending', 'queued'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count());
    }
}
