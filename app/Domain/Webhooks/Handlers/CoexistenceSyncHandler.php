<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Jobs\ImportCoexistenceBatch;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use Illuminate\Support\Facades\DB;

/**
 * Coexistence sync webhooks:
 *   history              — imports up to 180 days of chats (phases 0/1/2, chunks, progress; 2593109 = declined)
 *                          and later media contents for `media_placeholder` messages
 *   smb_app_state_sync   — the business's WhatsApp contacts (add / remove)
 * Imported messages are `history` origin: no unread, no windows, no automations.
 */
final class CoexistenceSyncHandler implements WebhookHandler
{
    private const HISTORY_DECLINED = 2593109;

    public function __construct(
        private readonly CoexistenceImport $import,
    ) {}

    public function fields(): array
    {
        return ['history', 'smb_app_state_sync'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        $number = $change->number;
        if ($number === null) {
            return ProcessResult::Ignored;
        }

        $job = CoexistenceSyncJob::query()->firstOrCreate(
            ['phone_number_id' => $number->id, 'sync_type' => $change->field],
            ['status' => 'in_progress'],
        );

        // Nothing is imported in this webhook worker. Records go to a waiting list in one bulk insert
        // and a low-priority job imports them in small batches (see CoexistenceImport): a large
        // history must never flood the database or delay live messages.
        if ($change->field === 'smb_app_state_sync') {
            $records = [];
            foreach ((array) ($change->value['state_sync'] ?? []) as $item) {
                if (($item['type'] ?? null) !== 'contact' || ($item['action'] ?? 'add') !== 'add') {
                    continue; // removals in the phone's address book never delete our contacts
                }
                $records[] = ['kind' => 'contact', 'thread_user' => null, 'payload' => (array) ($item['contact'] ?? [])];
            }
            $this->import->enqueue($number, $job, $records);
            ImportCoexistenceBatch::kick($number->tenant_id);
            CoexistenceSyncJob::query()->whereKey($job->id)->update(['status' => 'in_progress', 'chunks_received' => DB::raw('chunks_received + 1')]);

            return ProcessResult::Processed;
        }

        $records = [];
        // Media contents for earlier placeholders arrive as plain `messages` under field history.
        foreach ((array) ($change->value['messages'] ?? []) as $raw) {
            if (is_array($raw)) {
                $records[] = ['kind' => 'message', 'thread_user' => null, 'payload' => $raw];
            }
        }

        foreach ((array) ($change->value['history'] ?? []) as $chunk) {
            foreach ((array) ($chunk['errors'] ?? []) as $error) {
                if ((int) ($error['code'] ?? 0) === self::HISTORY_DECLINED) {
                    $job->forceFill(['status' => 'declined', 'error_code' => (string) self::HISTORY_DECLINED,
                        'error_message' => $error['title'] ?? 'History sharing is turned off by the business', 'completed_at' => now()])->save();
                    $number->forceFill(['coexistence_status' => CoexistenceStatus::Synced])->save();

                    return ProcessResult::Processed;
                }
            }

            foreach ((array) ($chunk['threads'] ?? []) as $thread) {
                $threadUser = isset($thread['id']) ? (string) $thread['id'] : null;
                if ($threadUser === null) {
                    continue;
                }
                foreach ((array) ($thread['messages'] ?? []) as $raw) {
                    if (is_array($raw)) {
                        $records[] = ['kind' => 'message', 'thread_user' => $threadUser, 'payload' => $raw];
                    }
                }
            }

            $meta = (array) ($chunk['metadata'] ?? []);

            // "progress" is how much of the history WhatsApp has sent so far. Several webhook workers
            // handle chunks at the same time and in any order, so the values are raised IN THE
            // DATABASE (never from this worker's own, possibly older, copy of the row): a late
            // "95%" must not overwrite the "100%" another worker has just written.
            CoexistenceSyncJob::query()->whereKey($job->id)->where('status', '!=', 'declined')->update([
                'status' => 'in_progress',
                'phase' => $meta['phase'] ?? DB::raw('phase'),
                'chunk_order' => DB::raw('GREATEST(COALESCE(chunk_order, 0), '.(int) ($meta['chunk_order'] ?? 0).')'),
                'progress' => DB::raw('GREATEST(progress, '.max(0, min(100, (int) ($meta['progress'] ?? 0))).')'),
                'chunks_received' => DB::raw('chunks_received + 1'),
            ]);
        }

        $this->import->enqueue($number, $job, $records);
        ImportCoexistenceBatch::kick($number->tenant_id);
        $this->import->finishIfDone($number);

        return ProcessResult::Processed;
    }
}
