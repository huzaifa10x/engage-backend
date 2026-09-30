<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** Stripe is payment infrastructure; our subscriptions table stays the source of truth. */
enum BillingProvider: string
{
    case Manual = 'manual';
    case Stripe = 'stripe';
}
