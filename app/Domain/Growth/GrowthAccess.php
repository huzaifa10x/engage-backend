<?php

declare(strict_types=1);

namespace App\Domain\Growth;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;

/**
 * What a workspace gets from Growth Hub (Change Notice CN-01 §III):
 *  - off   the workspace's pilot switch is off, or the plan has no lead source tracking;
 *  - chip  Starter (and Free): the Meta ad source chip only — no source details, evidence, Leads or Analytics;
 *  - full  Growth, Pro, Enterprise: Growth Hub and Analytics → Marketing.
 * The plan side reads the existing lead_source_tracking feature: level "basic" = chip only, included = full.
 * Super Admin can still override it per company. Evidence is recorded for chip and full alike, so an
 * upgrade shows history from day one.
 */
final class GrowthAccess
{
    public const OFF = 'off';

    public const CHIP = 'chip';

    public const FULL = 'full';

    public static function tier(Tenant $tenant): string
    {
        if (! GrowthSettings::for($tenant)->enabled) {
            return self::OFF;
        }

        $feature = app(EntitlementService::class)->for($tenant)->get(FeatureKey::LeadSourceTracking);
        if (! $feature->enabled) {
            return self::OFF;
        }

        return ($feature->config['level'] ?? null) === 'basic' ? self::CHIP : self::FULL;
    }

    public static function recording(Tenant $tenant): bool
    {
        return self::tier($tenant) !== self::OFF;
    }

    public static function full(Tenant $tenant): bool
    {
        return self::tier($tenant) === self::FULL;
    }
}
