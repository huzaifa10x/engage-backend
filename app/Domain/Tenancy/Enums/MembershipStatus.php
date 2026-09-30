<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
