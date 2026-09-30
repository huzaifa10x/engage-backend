<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** Live statuses grant the subscribed plan's entitlements (one live row per tenant, DB-enforced). */
    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    /** @return list<self> */
    public static function live(): array
    {
        return [self::Trialing, self::Active, self::PastDue];
    }
}
