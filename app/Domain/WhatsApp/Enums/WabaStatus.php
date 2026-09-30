<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

enum WabaStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
}
