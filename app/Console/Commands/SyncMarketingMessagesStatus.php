<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\WhatsApp\MarketingMessages;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Jobs\SyncMarketingMessages;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;

/**
 * Hourly safety net behind the MM_LITE_TERMS_SIGNED webhook: re-reads every connected account's
 * Marketing Messages status from Meta, and asks eligible businesses that were never asked (or not
 * for a week) to accept the terms. One queued job per account.
 */
final class SyncMarketingMessagesStatus extends Command
{
    protected $signature = 'engage:mm:sync {--no-enroll : Only read the status; do not ask anyone to accept the terms}';

    protected $description = 'Refresh the Marketing Messages API status of every connected WhatsApp Business Account.';

    public function handle(TenantContext $context): int
    {
        if (! MarketingMessages::enabled()) {
            $this->components->warn('The Marketing Messages API is switched off (META_MM_ENABLED=false).');

            return self::SUCCESS;
        }

        $ids = $context->bypass(fn () => WabaAccount::query()->where('status', WabaStatus::Connected)->pluck('id'));
        foreach ($ids as $id) {
            SyncMarketingMessages::dispatch((string) $id, ! $this->option('no-enroll'), 'backfill_batch');
        }
        $this->components->info("Queued a Marketing Messages check for {$ids->count()} account(s).");

        return self::SUCCESS;
    }
}
