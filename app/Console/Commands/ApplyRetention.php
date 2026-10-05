<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Compliance\RetentionRunner;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Daily: redact old messages and delete old media for every workspace that enabled retention. */
final class ApplyRetention extends Command
{
    protected $signature = 'engage:retention:apply';

    protected $description = 'Apply each workspace\'s data retention policy (redact old messages, delete old media).';

    public function handle(TenantContext $context, RetentionRunner $runner, AuditLogger $audit): int
    {
        $tenants = $context->bypass(fn () => Tenant::query()->whereRaw("(settings->'compliance'->>'retention_enabled')::boolean IS TRUE")->get());

        foreach ($tenants as $tenant) {
            try {
                $context->run($tenant, function () use ($runner, $tenant, $audit): void {
                    $result = $runner->apply($tenant);
                    if ($result['messages'] + $result['media'] > 0) {
                        $audit->record('retention.applied', $tenant, meta: $result);
                    }
                });
            } catch (Throwable $e) {
                Log::error('Retention run failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        $this->components->info("Retention applied for {$tenants->count()} workspace(s).");

        return self::SUCCESS;
    }
}
