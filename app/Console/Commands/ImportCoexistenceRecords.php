<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empties the waiting list left by the earlier, paced version of the import: everything in it is
 * imported in large batches with no hourly limit. New records are imported directly when they
 * arrive and never enter the list, so once it is empty this command has nothing to do.
 */
final class ImportCoexistenceRecords extends Command
{
    protected $signature = 'engage:coexistence:import';

    protected $description = 'Import any WhatsApp Business app records still in the old waiting list (no rate limit).';

    public function handle(TenantContext $context, CoexistenceImport $import): int
    {
        $tenantIds = $context->bypass(fn () => DB::table('coexistence_sync_items')->distinct()->pluck('tenant_id'));
        $total = 0;

        foreach ($tenantIds as $tenantId) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant !== null) {
                $total += (int) $context->run($tenant, fn () => $import->drainWaitingList($tenant));
            }
        }
        $this->components->info("Imported {$total} record(s) for {$tenantIds->count()} workspace(s).");

        return self::SUCCESS;
    }
}
