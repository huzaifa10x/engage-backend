<?php

declare(strict_types=1);

namespace App\Domain\Data\Jobs;

use App\Domain\Data\DataExports;
use App\Domain\Data\Models\DataExport;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Makes one export file (Settings › Data). A failure is shown on the export, never retried blindly. */
final class BuildDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $tenantId, public readonly string $exportId)
    {
        $this->onQueue(QueueName::Maintenance->value);
    }

    public function handle(TenantContext $context, DataExports $exports): void
    {
        $tenant = $context->bypass(fn () => Tenant::query()->find($this->tenantId));
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function () use ($exports): void {
            $export = DataExport::query()->find($this->exportId);
            if ($export === null || $export->status !== 'queued') {
                return;
            }
            $export->forceFill(['status' => 'running'])->save();
            try {
                $exports->build($export);
            } catch (Throwable $e) {
                Log::error('Data export failed', ['export' => $export->id, 'kind' => $export->kind, 'error' => $e->getMessage()]);
                $export->forceFill(['status' => 'failed', 'error' => 'The file could not be made. Try again, or contact support.', 'finished_at' => now()])->save();
            }
        });
    }
}
