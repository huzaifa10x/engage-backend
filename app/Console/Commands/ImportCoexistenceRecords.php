<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Every minute: import the next records from the WhatsApp Business app for each workspace, at its hourly pace. */
final class ImportCoexistenceRecords extends Command
{
    protected $signature = 'engage:coexistence:import';

    protected $description = 'Import waiting WhatsApp Business app records (contacts, chat history) at the per-workspace hourly rate.';

    public function handle(TenantContext $context, CoexistenceImport $import): int
    {
        $tenantIds = $context->bypass(fn () => DB::table('coexistence_sync_items')->distinct()->pluck('tenant_id'));
        $total = 0;

        foreach ($tenantIds as $tenantId) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant !== null) {
                $total += (int) $context->run($tenant, fn () => $import->importNext($tenant));
            }
        }
        $this->components->info("Imported {$total} record(s) for {$tenantIds->count()} workspace(s).");

        return self::SUCCESS;
    }
}
