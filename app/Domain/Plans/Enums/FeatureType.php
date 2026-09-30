<?php

declare(strict_types=1);

namespace App\Domain\Plans\Enums;

enum FeatureType: string
{
    /** On/off capability, optionally with a config level (API access, click tracking). */
    case Boolean = 'boolean';
    /** Countable capacity held at any moment; limit_value NULL = unlimited (seats, numbers, tags). */
    case Limit = 'limit';
    /** Consumption per calendar month; resets monthly (campaign reach, automation executions). */
    case Metered = 'metered';

    public function isQuantified(): bool
    {
        return $this !== self::Boolean;
    }
}
