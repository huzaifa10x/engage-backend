<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;

/** Numbers that occupy a plan slot: connected or still onboarding. Disconnected ones free it. */
final class WhatsappNumbersCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::WhatsappNumbers;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => PhoneNumber::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('status', [PhoneNumberStatus::Pending, PhoneNumberStatus::Connected])
            ->count());
    }
}
