<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
