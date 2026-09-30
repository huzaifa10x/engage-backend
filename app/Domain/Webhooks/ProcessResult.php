<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

enum ProcessResult: string
{
    case Processed = 'processed';
    case Ignored = 'ignored';
    /** Stored for a module that is not built yet (messages, templates); replay later. */
    case Deferred = 'deferred';
}
