<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use RuntimeException;

/** A bot message could not be sent (24-hour window closed, contact opted out, number offline …). */
final class BotSendFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
