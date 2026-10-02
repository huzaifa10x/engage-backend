<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Templates\TemplateSync;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net behind the template webhooks: re-reads every connected WABA's templates from Meta
 * so edits, deletions and status changes made in WhatsApp Manager always arrive, even if a
 * webhook was missed.
 */
final class SyncTemplates extends Command
{
    protected $signature = 'engage:templates:sync';

    protected $description = 'Sync WhatsApp message templates and their statuses from Meta for every connected account.';

    public function handle(TenantContext $context, TemplateSync $sync): int
    {
        $accounts = $context->bypass(fn () => WabaAccount::query()->where('status', WabaStatus::Connected)->get());
        $ok = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($account->tenant_id));
            if ($tenant === null) {
                continue;
            }

            try {
                $context->run($tenant, fn () => $sync->syncWaba($account));
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Scheduled template sync failed', ['waba_id' => $account->waba_id, 'error' => $e->getMessage()]);
            }
        }

        $this->components->info("Templates synced for {$ok} account(s); {$failed} failed.");

        return self::SUCCESS;
    }
}
