<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

enum CoexistenceStatus: string
{
    case None = 'none';
    case SyncPending = 'sync_pending';
    case HistorySyncing = 'history_syncing';
    case Synced = 'synced';
    case SyncFailed = 'sync_failed';
    case Offboarded = 'offboarded';
}
