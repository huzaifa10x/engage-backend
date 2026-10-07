<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Jobs\ImportCoexistenceBatch;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safety net, every minute: makes sure an import chain is running for every workspace that has
 * WhatsApp Business app records waiting. Normally the webhook starts the chain and each batch
 * queues the next; this restarts it if a worker was restarted or a job was lost.
 * With --now it imports one batch per workspace in this process (for maintenance and tests).
 */
final class ImportCoexistenceRecords extends Command
{
    protected $signature = 'engage:coexistence:import {--now : Import one batch per workspace in this process instead of queueing}';

    protected $description = 'Make sure waiting WhatsApp Business app records are being imported in batches.';

    public function handle(TenantContext $context, CoexistenceImport $import): int
    {
        $tenantIds = $context->bypass(fn () => DB::table('coexistence_sync_items')->distinct()->pluck('tenant_id'));
        $started = 0;

        foreach ($tenantIds as $tenantId) {
            if ($this->option('now')) {
                $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
                if ($tenant !== null) {
                    $context->run($tenant, fn () => $import->importBatch($tenant));
                    $started++;
                }
            } elseif (ImportCoexistenceBatch::kick((string) $tenantId)) {
                $started++;
            }
        }
        $this->components->info("{$tenantIds->count()} workspace(s) have records waiting; {$started} import chain(s) started.");

        return self::SUCCESS;
    }
}
