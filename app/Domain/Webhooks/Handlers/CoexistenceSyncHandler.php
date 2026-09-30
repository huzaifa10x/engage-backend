<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;

/**
 * history / smb_app_state_sync: tracks sync progress now (phase, chunk_order, progress; 2593109 =
 * the business declined history sharing). The message and contact CONTENT is imported by the
 * messaging module, so these rows stay `deferred` for replay — nothing is lost in the meantime.
 */
final class CoexistenceSyncHandler implements WebhookHandler
{
    private const HISTORY_DECLINED = 2593109;

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

        if ($change->field === 'smb_app_state_sync') {
            $job->forceFill(['status' => 'in_progress', 'chunks_received' => $job->getAttribute('chunks_received') + 1])->save();

            return ProcessResult::Deferred;
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

            $meta = (array) ($chunk['metadata'] ?? []);
            $progress = max((int) $job->progress, (int) ($meta['progress'] ?? 0));

            $job->forceFill([
                'status' => $progress >= 100 ? 'completed' : 'in_progress',
                'phase' => $meta['phase'] ?? $job->getAttribute('phase'),
                'chunk_order' => max((int) $job->getAttribute('chunk_order'), (int) ($meta['chunk_order'] ?? 0)),
                'progress' => $progress,
                'chunks_received' => $job->getAttribute('chunks_received') + 1,
                'completed_at' => $progress >= 100 ? ($job->getAttribute('completed_at') ?? now()) : null,
            ])->save();

            if ($progress >= 100) {
                $number->forceFill(['coexistence_status' => CoexistenceStatus::Synced])->save();
            }
        }

        return ProcessResult::Deferred;
    }
}
