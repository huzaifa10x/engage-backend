<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Where a WhatsApp Business Account stands with Meta's Marketing Messages API. Only Onboarded sends
 * marketing through the MM API; every other state sends it through the Cloud API, so a campaign is
 * never blocked by this status.
 */
enum MarketingMessagesStatus: string
{
    case NotStarted = 'not_started';   // not checked yet, or Meta reports nothing
    case Eligible = 'eligible';        // can onboard; the terms are not accepted yet
    case RequestSent = 'request_sent'; // the business was asked to accept; waiting for them / Meta
    case Onboarded = 'onboarded';      // terms accepted: marketing goes through the MM API
    case NotEligible = 'not_eligible'; // Meta does not offer the MM API to this account

    public function sendsThroughMmApi(): bool
    {
        return $this === self::Onboarded;
    }
}
