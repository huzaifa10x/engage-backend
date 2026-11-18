<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Admin\DeleteAccounts;
use App\Application\Tenancy\WorkspaceDeletion;
use App\Domain\Data\Models\DataExport;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Hourly: erases workspaces whose deletion waiting period is over, and removes expired export files. */
final class RunWorkspaceDeletions extends Command
{
    protected $signature = 'engage:workspace-deletions';

    protected $description = 'Delete workspaces whose 30-day waiting period is over, and expired data exports';

    public function handle(WorkspaceDeletion $deletion, DeleteAccounts $delete, TenantContext $context): int
    {
        $done = $deletion->runDue($delete);
        $expired = $context->bypass(function (): int {
            $list = DataExport::query()->where('expires_at', '<=', now())->get();
            foreach ($list as $export) {
                if ($export->path !== null) {
                    Storage::disk((string) $export->disk)->delete($export->path);
                }
                $export->delete();
            }

            return $list->count();
        });
        $this->info("Workspaces deleted: {$done}. Expired exports removed: {$expired}.");

        return self::SUCCESS;
    }
}
