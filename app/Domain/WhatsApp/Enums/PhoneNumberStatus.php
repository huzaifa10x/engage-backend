<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

enum PhoneNumberStatus: string
{
    case Pending = 'pending';           // onboarding in progress (register not yet done)
    case Connected = 'connected';
    case Disconnected = 'disconnected'; // partner removed / offboarded / disconnected by the tenant
}
