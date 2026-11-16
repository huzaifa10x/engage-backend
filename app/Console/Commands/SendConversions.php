<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversions\Jobs\SendConversionEvents;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Every minute: one send job per WhatsApp account with conversion events waiting (queued). */
final class SendConversions extends Command
{
    protected $signature = 'engage:conversions:send';

    protected $description = 'Queue the sending of waiting conversion events to Meta, per WhatsApp account.';

    public function handle(TenantContext $context): int
    {
        $accounts = $context->bypass(fn () => DB::table('conversion_events')->where('status', 'queued')->whereNotNull('waba_id')
            ->select('tenant_id', 'waba_id')->distinct()->get());
        foreach ($accounts as $row) {
            SendConversionEvents::dispatch((string) $row->tenant_id, (string) $row->waba_id);
        }
        $this->info('Accounts with events waiting: '.$accounts->count().'.');

        return self::SUCCESS;
    }
}
