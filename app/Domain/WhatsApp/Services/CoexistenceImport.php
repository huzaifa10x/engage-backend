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
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The paced import of WhatsApp Business app data (coexistence).
 *
 *   receive  → webhooks put each contact and each history message into a waiting list, counted
 *   import   → a scheduled run takes records off the list at a fixed rate per workspace
 *   progress → received / imported / remaining, plus how far WhatsApp is with sending
 *
 * The rate is a hard ceiling per workspace per hour (default 200), spread evenly across the hour
 * rather than spent in one burst. It is enforced here, on the server; nothing a browser sends can
 * change it.
 */
final class CoexistenceImport
{
    private const KIND_JOB = ['contact' => 'smb_app_state_sync', 'message' => 'history'];

    public function __construct(private readonly ContactResolver $contacts, private readonly MessageRecorder $recorder) {}

    public static function perHour(): int
    {
        return max(1, (int) config('engage.meta.coexistence_import_per_hour', 200));
    }

    /**
     * Adds received records to the waiting list.
     *
     * @param  list<array{kind: string, thread_user: ?string, payload: array<string, mixed>}>  $records
     */
    public function enqueue(PhoneNumber $number, CoexistenceSyncJob $job, array $records): void
    {
        if ($records === []) {
            return;
        }
        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('coexistence_sync_items')->insert(array_map(fn (array $r) => [
                'tenant_id' => $number->tenant_id, 'phone_number_id' => $number->id, 'kind' => $r['kind'],
                'thread_user' => $r['thread_user'], 'payload' => json_encode($r['payload'], JSON_UNESCAPED_UNICODE), 'created_at' => now(),
            ], $chunk));
        }
        CoexistenceSyncJob::query()->whereKey($job->id)->update(['records_received' => DB::raw('records_received + '.count($records))]);
    }

    /**
     * Imports the next records for one workspace, within its hourly allowance.
     * Must run inside that workspace's tenant context. Returns how many were imported.
     */
    public function importNext(Tenant $tenant, int $runsPerHour = 60): int
    {
        $limit = self::perHour();
        $key = 'coex-import:'.$tenant->id;
        // An even pace: each run takes its share of the hour, never more than what is left of the allowance.
        $take = min((int) ceil($limit / $runsPerHour), RateLimiter::remaining($key, $limit));
        if ($take <= 0) {
            return 0;
        }

        $items = DB::table('coexistence_sync_items')->where('tenant_id', $tenant->id)->orderBy('id')->limit($take)->get();
        $numbers = PhoneNumber::query()->whereIn('id', $items->pluck('phone_number_id')->unique())->get()->keyBy('id');
        $done = [];

        foreach ($items as $item) {
            $number = $numbers[$item->phone_number_id] ?? null;
            try {
                if ($number !== null) {
                    $this->import($number, (string) $item->kind, $item->thread_user, (array) json_decode((string) $item->payload, true));
                }
            } catch (Throwable $e) {
                // One unreadable record must not block the thousands behind it.
                Log::warning('Coexistence record skipped', ['item' => $item->id, 'error' => $e->getMessage()]);
            }
            RateLimiter::hit($key, 3600);
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

    /** History is finished when WhatsApp has sent everything AND everything received has been imported. */
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
        $remaining = $received - $imported;
        // WhatsApp reports how far it is with SENDING history (0–100). It has finished when that is 100, or history was declined.
        // ("completed" below 100% means the import was closed after WhatsApp went quiet: see WatchCoexistenceSync.)
        $whatsappDone = ($history === null && $number->coexistence_status === CoexistenceStatus::Synced)
            || ($history !== null && ($history->progress >= 100 || in_array($history->status, ['declined', 'completed'], true)));
        $perHour = self::perHour();

        return [
            'state' => match (true) {
                $number->coexistence_status === CoexistenceStatus::SyncFailed => 'failed',
                $remaining === 0 && $whatsappDone => 'complete',
                $received === 0 => 'waiting',          // requested, nothing has arrived yet
                default => 'importing',
            },
            'history_declined' => $history?->status === 'declined',
            'whatsapp_progress' => $history === null ? null : (int) $history->progress,   // % sent by WhatsApp so far
            'whatsapp_finished' => $whatsappDone,
            'received' => $received,
            'imported' => $imported,
            'remaining' => $remaining,
            // Of what has arrived so far. More may still arrive while whatsapp_finished is false.
            'percent' => $received > 0 ? (int) floor($imported / $received * 100) : ($whatsappDone ? 100 : 0),
            'contacts' => $contacts,
            'messages' => $messages,
            'per_hour' => $perHour,
            'minutes_left' => $remaining > 0 ? (int) ceil($remaining / $perHour * 60) : 0,
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
