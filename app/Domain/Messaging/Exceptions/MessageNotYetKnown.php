<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Exceptions;

use RuntimeException;

/** A status webhook arrived before the outbound message row carried its wamid — retry later. */
final class MessageNotYetKnown extends RuntimeException
{
    public function __construct(public readonly string $wamid)
    {
        parent::__construct("No message with wamid [{$wamid}] yet.");
    }
}
