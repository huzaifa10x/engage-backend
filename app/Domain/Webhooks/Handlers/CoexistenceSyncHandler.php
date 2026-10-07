<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Services\CoexistenceImport;

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

        // Nothing is imported here: records join a waiting list and are imported at a fixed pace
        // per workspace (CoexistenceImport), which is also what makes exact progress possible.
        if ($change->field === 'smb_app_state_sync') {
            $records = [];
            foreach ((array) ($change->value['state_sync'] ?? []) as $item) {
                if (($item['type'] ?? null) !== 'contact' || ($item['action'] ?? 'add') !== 'add') {
                    continue; // removals in the phone's address book never delete our contacts
                }
                $records[] = ['kind' => 'contact', 'thread_user' => null, 'payload' => (array) ($item['contact'] ?? [])];
            }
            $this->import->enqueue($number, $job, $records);
            $job->forceFill(['status' => 'in_progress', 'chunks_received' => $job->getAttribute('chunks_received') + 1])->save();

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
            $progress = max((int) $job->progress, (int) ($meta['progress'] ?? 0));

            // "progress" is how much WhatsApp has SENT. The job is complete only when that is 100
            // and everything received has also been imported (CoexistenceImport::finishIfDone).
            $job->forceFill([
                'status' => 'in_progress',
                'phase' => $meta['phase'] ?? $job->getAttribute('phase'),
                'chunk_order' => max((int) $job->getAttribute('chunk_order'), (int) ($meta['chunk_order'] ?? 0)),
                'progress' => $progress,
                'chunks_received' => $job->getAttribute('chunks_received') + 1,
            ])->save();
        }

        $this->import->enqueue($number, $job, $records);
        $this->import->finishIfDone($number);

        return ProcessResult::Processed;
    }
}
