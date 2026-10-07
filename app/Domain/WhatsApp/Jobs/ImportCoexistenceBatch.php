<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Jobs;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Imports ONE batch of waiting WhatsApp Business app records for one workspace, then queues the
 * next batch after a pause, until that workspace's waiting list is empty.
 *
 *  • Low-priority queue with very few workers: an import can never crowd out live messages,
 *    the inbox, or sending.
 *  • One chain per workspace: a marker in the cache says "a batch is queued or running", so
 *    however many webhooks arrive, batches never pile up or run side by side.
 *  • Small batch + pause: steady, bounded load on the database instead of one large spike.
 *  • Self-healing: if a worker dies mid-chain the marker expires, and the scheduler (every
 *    minute) starts the chain again for any workspace that still has records waiting.
 */
final class ImportCoexistenceBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const MARKER_SECONDS = 300;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue(QueueName::Maintenance->value);
    }

    /** Start importing for a workspace unless a batch is already queued or running for it. */
    public static function kick(string $tenantId): bool
    {
        if (! Cache::add(self::marker($tenantId), 1, self::MARKER_SECONDS)) {
            return false;
        }
        self::dispatch($tenantId);

        return true;
    }

    public function handle(TenantContext $context, CoexistenceImport $import): void
    {
        $tenant = $context->bypass(fn () => Tenant::query()->find($this->tenantId));
        $left = $tenant === null ? 0 : (int) $context->run($tenant, fn () => $import->importBatch($tenant));

        if ($left > 0) {
            Cache::put(self::marker($this->tenantId), 1, self::MARKER_SECONDS);
            self::dispatch($this->tenantId)->delay(CoexistenceImport::pauseSeconds());

            return;
        }
        Cache::forget(self::marker($this->tenantId));
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget(self::marker($this->tenantId)); // let the scheduler restart the chain
    }

    private static function marker(string $tenantId): string
    {
        return 'coex-import-chain:'.$tenantId;
    }
}
