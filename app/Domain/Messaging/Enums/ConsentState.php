<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Enums;

enum ConsentState: string
{
    case Unknown = 'unknown';
    case OptedIn = 'opted_in';
    case OptedOut = 'opted_out';
}
