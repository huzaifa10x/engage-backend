<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/** How a number reached Cloud API. Source of truth for coexistence — per number, not per tenant. */
enum OnboardingType: string
{
    case NewNumber = 'new_number';
    case Migrated = 'migrated';
    case Coexistence = 'coexistence';
}
