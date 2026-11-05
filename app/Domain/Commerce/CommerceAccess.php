<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Exceptions\FeatureNotAvailable;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Sales from chats (Engagement Plan v4 §VII.3): the WooCommerce plugin, Growth Hub › Sales and
 * Analytics › Sales & Revenue are Pro — the integrations feature at level "all" (or a custom
 * Enterprise level). Store messages on lower plans are unchanged.
 */
final class CommerceAccess
{
    public const LEVELS = ['all', 'custom'];

    public static function allows(Tenant $tenant): bool
    {
        $feature = app(EntitlementService::class)->for($tenant)->get(FeatureKey::Integrations);

        return $feature->enabled && in_array($feature->config['level'] ?? null, self::LEVELS, true);
    }

    public static function ensure(Tenant $tenant): void
    {
        if (! self::allows($tenant)) {
            throw new FeatureNotAvailable(FeatureKey::Integrations, app(EntitlementService::class)->for($tenant)->planKey);
        }
    }
}
