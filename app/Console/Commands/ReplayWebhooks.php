<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\Jobs\ProcessWebhookChange;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Re-processes stored webhooks, e.g. once the messaging module ships:
 *   php artisan engage:webhooks:replay --status=deferred --field=messages --since=2026-10-01
 */
final class ReplayWebhooks extends Command
{
    protected $signature = 'engage:webhooks:replay {--status=failed : failed|deferred} {--field=} {--waba=} {--since=} {--limit=5000}';

    protected $description = 'Re-dispatch stored Meta webhooks for processing (oldest first).';

    public function handle(TenantContext $context): int
    {
        $status = (string) $this->option('status');
        if (! in_array($status, ['failed', 'deferred'], true)) {
            $this->components->error('--status must be failed or deferred.');

            return self::FAILURE;
        }

        $rows = $context->bypass(fn () => DB::table('webhook_inbound_log')
            ->where('process_status', $status)
            ->when($this->option('field'), fn ($q, $f) => $q->where('field', $f))
            ->when($this->option('waba'), fn ($q, $w) => $q->where('waba_id', $w))
            ->when($this->option('since'), fn ($q, $s) => $q->where('received_at', '>=', Carbon::parse($s)))
            ->orderBy('received_at')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'received_at']));

        foreach ($rows as $row) {
            $context->bypass(fn () => DB::table('webhook_inbound_log')->where('id', $row->id)->where('received_at', $row->received_at)
                ->update(['process_status' => 'pending']));
            ProcessWebhookChange::dispatch($row->id, Carbon::parse($row->received_at)->toIso8601String());
        }

        $this->components->info("Re-dispatched {$rows->count()} webhook change(s).");

        return self::SUCCESS;
    }
}
