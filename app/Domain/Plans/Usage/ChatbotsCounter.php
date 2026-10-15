<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Chatbot flows in the workspace (drafts included). */
final class ChatbotsCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::Chatbots;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => ChatbotFlow::query()->where('tenant_id', $tenant->getKey())->count());
    }
}
