<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Services\ContactIdentity;
use App\Domain\Messaging\Services\ContactResolver;
use App\Domain\Messaging\Services\MessageRecorder;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;

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
        private readonly ContactResolver $contacts,
        private readonly MessageRecorder $recorder,
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

        if ($change->field === 'smb_app_state_sync') {
            foreach ((array) ($change->value['state_sync'] ?? []) as $item) {
                if (($item['type'] ?? null) !== 'contact' || ($item['action'] ?? 'add') !== 'add') {
                    continue; // removals in the phone's address book never delete our contacts
                }
                $c = (array) ($item['contact'] ?? []);
                $contact = $this->contacts->resolve(new ContactIdentity(
                    isset($c['phone_number']) ? preg_replace('/\D+/', '', (string) $c['phone_number']) : null,
                    isset($c['user_id']) ? (string) $c['user_id'] : null,
                ), 'app_sync');
                if ($contact !== null && $contact->name === null && ! empty($c['full_name'])) {
                    $contact->forceFill(['name' => mb_substr((string) $c['full_name'], 0, 190)])->save();
                }
            }
            $job->forceFill(['status' => 'in_progress', 'chunks_received' => $job->getAttribute('chunks_received') + 1])->save();

            return ProcessResult::Processed;
        }

        // Media contents for earlier placeholders arrive as plain `messages` under field history.
        foreach ((array) ($change->value['messages'] ?? []) as $raw) {
            if (is_array($raw)) {
                $contact = $this->contacts->resolve(ContactIdentity::fromInbound($raw, []), 'history');
                if ($contact !== null) {
                    $this->recorder->record($number, $contact, $raw, MessageOrigin::History);
                }
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
                $isBsuid = $threadUser !== null && str_contains($threadUser, '.');
                $contact = $this->contacts->resolve(new ContactIdentity($isBsuid ? null : $threadUser, $isBsuid ? $threadUser : null), 'history');
                if ($contact === null) {
                    continue;
                }
                foreach ((array) ($thread['messages'] ?? []) as $raw) {
                    if (is_array($raw)) {
                        $this->recorder->record($number, $contact, $raw, MessageOrigin::History);
                    }
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

        return ProcessResult::Processed;
    }
}
