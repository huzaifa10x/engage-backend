<?php

declare(strict_types=1);

namespace App\Domain\Plans\Enums;

/**
 * Pricing/feature changes create a NEW version; existing subscribers stay on their version
 * (grandfathering) until migrated deliberately. Active versions are never edited in place.
 */
enum PlanVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';
}
