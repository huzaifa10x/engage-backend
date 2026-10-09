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
    /** Webhooks that are safe to process a second time: the import skips what a workspace already has. */
    private const REPLAYABLE_WHEN_PROCESSED = ['history', 'smb_app_state_sync'];

    protected $signature = 'engage:webhooks:replay
        {--status=failed : failed|deferred, or processed (only with --field=history or --field=smb_app_state_sync)}
        {--field=} {--waba=} {--phone= : Meta phone number id} {--since=} {--limit=5000}';

    protected $description = 'Re-dispatch stored Meta webhooks for processing (oldest first).';

    public function handle(TenantContext $context): int
    {
        $status = (string) $this->option('status');
        // Already-processed webhooks are replayed only for the WhatsApp Business app import (chat history
        // and contacts), and only for one account or number: used to import again history that was
        // received but could not be stored. Everything else stays limited to failed / deferred.
        $again = $status === 'processed';
        if ($again && (! in_array((string) $this->option('field'), self::REPLAYABLE_WHEN_PROCESSED, true) || (! $this->option('waba') && ! $this->option('phone')))) {
            $this->components->error('--status=processed needs --field=history (or smb_app_state_sync) and --waba or --phone.');

            return self::FAILURE;
        }
        if (! $again && ! in_array($status, ['failed', 'deferred'], true)) {
            $this->components->error('--status must be failed or deferred.');

            return self::FAILURE;
        }

        $rows = $context->bypass(fn () => DB::table('webhook_inbound_log')
            ->where('process_status', $status)
            ->when($this->option('field'), fn ($q, $f) => $q->where('field', $f))
            ->when($this->option('waba'), fn ($q, $w) => $q->where('waba_id', $w))
            ->when($this->option('phone'), fn ($q, $p) => $q->where('phone_number_id', $p))
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
