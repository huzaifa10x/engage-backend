<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\WhatsApp\MarketingMessages;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time backfill for accounts connected before the Marketing Messages API: checks each account's
 * status with Meta right now, asks every eligible business to accept the terms, and prints the result.
 *
 *   php artisan engage:mm:enroll              all connected accounts
 *   php artisan engage:mm:enroll --check      status only, ask nobody
 *   php artisan engage:mm:enroll --tenant=ID  one workspace
 */
final class EnrollMarketingMessages extends Command
{
    protected $signature = 'engage:mm:enroll {--tenant= : Only this workspace (ID)} {--check : Only read the status from Meta}';

    protected $description = 'Check and enroll existing WhatsApp Business Accounts in the Marketing Messages API.';

    public function handle(TenantContext $context, MarketingMessages $mm): int
    {
        if (! MarketingMessages::enabled()) {
            $this->components->error('The Marketing Messages API is switched off (META_MM_ENABLED=false).');

            return self::FAILURE;
        }

        $wabas = $context->bypass(fn () => WabaAccount::query()->where('status', WabaStatus::Connected)
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->orderBy('tenant_id')->get());

        $rows = [];
        foreach ($wabas as $waba) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($waba->tenant_id));
            if ($tenant === null) {
                continue;
            }
            $result = $context->run($tenant, function () use ($mm, $waba) {
                try {
                    if ($this->option('check')) {
                        $mm->sync($waba, 'backfill');

                        return 'checked';
                    }

                    return $mm->enroll($waba, 'backfill_batch');
                } catch (Throwable $e) {
                    return 'error: '.mb_substr($e->getMessage(), 0, 80);
                }
            });
            $rows[] = [$tenant->name, $waba->name ?? $waba->waba_id, $waba->waba_id, $waba->mm_status?->value, $result, (string) $waba->getAttribute('mm_request_error')];
        }

        $this->table(['Workspace', 'Account', 'WABA ID', 'MM status', 'Result', 'Note'], $rows);
        $this->components->info(count($rows).' account(s) processed. "awaiting_client": a portfolio admin accepts in WhatsApp Manager › Overview › Alerts.');

        return self::SUCCESS;
    }
}
