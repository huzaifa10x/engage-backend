<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Services;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Services\ContactIdentity;
use App\Domain\Messaging\Services\ContactResolver;
use App\Domain\Messaging\Services\MessageRecorder;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import of WhatsApp Business app data (coexistence): contacts and chat history.
 *
 * Records are imported as soon as WhatsApp delivers them; nothing is held back or paced here.
 * How fast an import goes is decided by WhatsApp, which sends history in batches over time.
 * This class also counts what has been imported, so the portal can show real progress.
 */
final class CoexistenceImport
{
    private const KIND_JOB = ['contact' => 'smb_app_state_sync', 'message' => 'history'];

    public function __construct(private readonly ContactResolver $contacts, private readonly MessageRecorder $recorder) {}

    /**
     * Imports records the moment they arrive, and counts them.
     *
     * @param  list<array{kind: string, thread_user: ?string, payload: array<string, mixed>}>  $records
     */
    public function record(PhoneNumber $number, CoexistenceSyncJob $job, array $records): void
    {
        if ($records === []) {
            return;
        }
        foreach ($records as $record) {
            try {
                $this->import($number, $record['kind'], $record['thread_user'], $record['payload']);
            } catch (Throwable $e) {
                // One unreadable record must not stop the rest of the batch.
                Log::warning('Coexistence record skipped', ['phone_number' => $number->id, 'error' => $e->getMessage()]);
            }
        }
        $count = count($records);
        CoexistenceSyncJob::query()->whereKey($job->id)->update([
            'records_received' => DB::raw("records_received + {$count}"), 'records_imported' => DB::raw("records_imported + {$count}"), 'last_imported_at' => now(),
        ]);
    }

    /**
     * Records that were still waiting in the list of the earlier, paced version are imported here,
     * a large batch at a time with no hourly limit, until the list is empty. New records never
     * enter that list any more. Must run inside the workspace's tenant context.
     */
    public function drainWaitingList(Tenant $tenant, int $batch = 1000): int
    {
        $items = DB::table('coexistence_sync_items')->where('tenant_id', $tenant->id)->orderBy('id')->limit($batch)->get();
        if ($items->isEmpty()) {
            return 0;
        }
        $numbers = PhoneNumber::query()->whereIn('id', $items->pluck('phone_number_id')->unique())->get()->keyBy('id');
        $done = [];

        foreach ($items as $item) {
            $number = $numbers[$item->phone_number_id] ?? null;
            try {
                if ($number !== null) {
                    $this->import($number, (string) $item->kind, $item->thread_user, (array) json_decode((string) $item->payload, true));
                }
            } catch (Throwable $e) {
                Log::warning('Coexistence record skipped', ['item' => $item->id, 'error' => $e->getMessage()]);
            }
            $done[$item->phone_number_id][$item->kind] = ($done[$item->phone_number_id][$item->kind] ?? 0) + 1;
        }

        DB::table('coexistence_sync_items')->whereIn('id', $items->pluck('id'))->delete();
        foreach ($done as $numberId => $kinds) {
            foreach ($kinds as $kind => $count) {
                CoexistenceSyncJob::query()->where('phone_number_id', $numberId)->where('sync_type', self::KIND_JOB[$kind])
                    ->update(['records_imported' => DB::raw("records_imported + {$count}"), 'last_imported_at' => now(), 'updated_at' => now()]);
            }
            if (isset($numbers[$numberId])) {
                $this->finishIfDone($numbers[$numberId]);
            }
        }

        return $items->count();
    }

    /** History is finished when WhatsApp has sent everything (and nothing is left in the old waiting list). */
    public function finishIfDone(PhoneNumber $number): void
    {
        $history = CoexistenceSyncJob::query()->where('phone_number_id', $number->id)->where('sync_type', 'history')->first();
        if ($history === null || $history->progress < 100 || $history->status === 'declined') {
            return;
        }
        if (DB::table('coexistence_sync_items')->where('phone_number_id', $number->id)->where('kind', 'message')->exists()) {
            return;
        }
        $history->forceFill(['status' => 'completed', 'completed_at' => $history->completed_at ?? now()])->save();
        if ($number->coexistence_status !== CoexistenceStatus::Synced) {
            $number->forceFill(['coexistence_status' => CoexistenceStatus::Synced])->save();
        }
    }

    /**
     * What to show the user for one number.
     *
     * @return array<string, mixed>|null null when the number is not shared with the WhatsApp Business app
     */
    public function progress(PhoneNumber $number): ?array
    {
        if (! $number->isCoexistence()) {
            return null;
        }
        $jobs = CoexistenceSyncJob::query()->where('phone_number_id', $number->id)->get()->keyBy('sync_type');
        $part = function (?CoexistenceSyncJob $job): array {
            $received = (int) ($job->records_received ?? 0);
            $imported = min($received, (int) ($job->records_imported ?? 0));

            return ['received' => $received, 'imported' => $imported, 'remaining' => $received - $imported];
        };
        $contacts = $part($jobs['smb_app_state_sync'] ?? null);
        $messages = $part($jobs['history'] ?? null);
        $history = $jobs['history'] ?? null;

        $received = $contacts['received'] + $messages['received'];
        $imported = $contacts['imported'] + $messages['imported'];
        // WhatsApp reports how far it is with SENDING history (0–100). It has finished when that is 100, history was
        // declined, or the import was closed after WhatsApp went quiet (see WatchCoexistenceSync).
        $whatsappDone = ($history === null && $number->coexistence_status === CoexistenceStatus::Synced)
            || ($history !== null && ($history->progress >= 100 || in_array($history->status, ['declined', 'completed'], true)));
        $remaining = $received - $imported; // only ever above zero while the old waiting list is being emptied

        return [
            'state' => match (true) {
                $number->coexistence_status === CoexistenceStatus::SyncFailed => 'failed',
                $remaining === 0 && $whatsappDone => 'complete',
                $received === 0 => 'waiting',          // requested, nothing has arrived yet
                default => 'importing',
            },
            'history_declined' => $history?->status === 'declined',
            // The only measure of "how much is left" that exists: WhatsApp does not say how many records it will
            // send, only what share of the history it has sent so far.
            'percent' => $whatsappDone ? ($remaining === 0 ? 100 : 99) : (int) ($history->progress ?? 0),
            'whatsapp_finished' => $whatsappDone,
            'imported' => $imported,
            'waiting' => $remaining,
            'contacts' => $contacts['imported'],
            'messages' => $messages['imported'],
            'started_at' => $number->getAttribute('app_sync_started_at')?->toIso8601String(),
            'last_imported_at' => $jobs->max('last_imported_at')?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function import(PhoneNumber $number, string $kind, ?string $threadUser, array $payload): void
    {
        if ($kind === 'contact') {
            $contact = $this->contacts->resolve(new ContactIdentity(
                isset($payload['phone_number']) ? preg_replace('/\D+/', '', (string) $payload['phone_number']) : null,
                isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            ), 'app_sync');
            if ($contact !== null && $contact->name === null && ! empty($payload['full_name'])) {
                $contact->forceFill(['name' => mb_substr((string) $payload['full_name'], 0, 190)])->save();
            }

            return;
        }

        if ($threadUser !== null) {
            $isBsuid = str_contains($threadUser, '.');
            $identity = new ContactIdentity($isBsuid ? null : $threadUser, $isBsuid ? $threadUser : null);
        } else {
            $identity = ContactIdentity::fromInbound($payload, []);
        }
        $contact = $this->contacts->resolve($identity, 'history');
        if ($contact !== null) {
            $this->recorder->record($number, $contact, $payload, MessageOrigin::History);
        }
    }
}
