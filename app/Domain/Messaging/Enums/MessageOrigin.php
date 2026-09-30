<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Enums;

enum MessageOrigin: string
{
    case Customer = 'customer';
    case Agent = 'agent';
    case Api = 'api';
    case Campaign = 'campaign';
    case Automation = 'automation';
    case AppEcho = 'app_echo';   // sent from the WhatsApp Business app (coexistence)
    case History = 'history';    // imported coexistence chat history

    /** Mirrored/imported traffic never opens windows, bumps unread or triggers automations. */
    public function isMirror(): bool
    {
        return $this === self::AppEcho || $this === self::History;
    }
}
