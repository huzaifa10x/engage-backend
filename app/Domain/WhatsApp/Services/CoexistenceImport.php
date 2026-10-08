<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Services;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
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
 * WhatsApp can deliver tens of thousands of records within minutes. Importing them the moment
 * they arrive floods the database, the realtime channel and Meta's media API all at once, and
 * starves live messages. So the import is split in two:
 *
 *   1. RECEIVE (webhook)  — records are written to a waiting list in one cheap bulk insert and
 *                            counted. Nothing else happens in the webhook worker.
 *   2. IMPORT (queue job) — ImportCoexistenceBatch takes a small batch off the list, imports it,
 *                            pauses, and repeats until the list is empty. One batch at a time per
 *                            workspace, on the "sync" queue, whose few workers are the hard cap
 *                            on how much of the database an import can ever use.
 *
 * This is load control for the system, not a customer-facing limit: the batch size and pause are
 * server settings (see config/engage.php → meta.coexistence_import_*).
 */
final class CoexistenceImport
{
    private const KIND_JOB = ['contact' => 'smb_app_state_sync', 'message' => 'history'];

    public function __construct(private readonly ContactResolver $contacts, private readonly MessageRecorder $recorder) {}

    /** @var array<string, Contact> contacts already resolved in the batch being imported */
    private array $contactCache = [];

    public static function batchSize(): int
    {
        return max(1, min(1000, (int) config('engage.meta.coexistence_import_batch', 100)));
    }

    public static function pauseSeconds(): int
    {
        return max(0, (int) config('engage.meta.coexistence_import_pause_seconds', 5));
    }

    /**
     * Step 1: put received records on the waiting list (one bulk insert) and count them.
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
     * Step 2: import the next batch for one workspace. Must run inside that workspace's tenant
     * context. Returns how many records are still waiting afterwards.
     */
    public function importBatch(Tenant $tenant, ?int $size = null): int
    {
        $items = DB::table('coexistence_sync_items')->where('tenant_id', $tenant->id)->orderBy('id')->limit($size ?? self::batchSize())->get();
        if ($items->isEmpty()) {
            return 0;
        }
        $numbers = PhoneNumber::query()->whereIn('id', $items->pluck('phone_number_id')->unique())->get()->keyBy('id');
        $done = [];

        // Idempotent and cheap to repeat: one query finds the messages of this batch that are already
        // stored (a retried batch, a webhook Meta sent twice, a message that also arrived live), and
        // those are skipped without touching the conversation at all.
        $payloads = $items->mapWithKeys(fn (object $item) => [$item->id => (array) json_decode((string) $item->payload, true)]);
        $wamids = $payloads->map(fn (array $p) => $p['id'] ?? null)->filter()->values()->all();
        $existing = $wamids === [] ? [] : array_flip(DB::table('messages')->whereIn('wamid', $wamids)->pluck('wamid')->all());
        $this->contactCache = [];

        foreach ($items as $item) {
            $number = $numbers[$item->phone_number_id] ?? null;
            $payload = $payloads[$item->id];
            try {
                $duplicate = $item->kind === 'message' && isset($payload['id'], $existing[$payload['id']]);
                if ($number !== null && ! $duplicate) {
                    $this->import($number, (string) $item->kind, $item->thread_user, $payload);
                }
            } catch (Throwable $e) {
                // One unreadable record must not block the thousands behind it.
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

        return DB::table('coexistence_sync_items')->where('tenant_id', $tenant->id)->count();
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
        $waiting = $received - $imported;
        // WhatsApp reports how far it is with SENDING history (0–100). It has finished when that is 100, history was
        // declined, or the import was closed after WhatsApp went quiet (see WatchCoexistenceSync).
        $whatsappDone = ($history === null && $number->coexistence_status === CoexistenceStatus::Synced)
            || ($history !== null && ($history->progress >= 100 || in_array($history->status, ['declined', 'completed'], true)));

        return [
            'state' => match (true) {
                $number->coexistence_status === CoexistenceStatus::SyncFailed => 'failed',
                $waiting === 0 && $whatsappDone => 'complete',
                $received === 0 => 'waiting',          // requested, nothing has arrived yet
                default => 'importing',
            },
            'history_declined' => $history?->status === 'declined',
            // Two different measures, shown separately:
            'whatsapp_percent' => $whatsappDone ? 100 : (int) ($history->progress ?? 0),     // how much WhatsApp has sent us
            'whatsapp_finished' => $whatsappDone,
            'received' => $received,                                                          // records that have arrived
            'imported' => $imported,                                                          // … of which are in the inbox
            'waiting' => $waiting,                                                            // … and still in the waiting list
            'percent' => $received > 0 ? (int) floor($imported / $received * 100) : ($whatsappDone ? 100 : 0),
            'contacts' => ['received' => $contacts['received'], 'imported' => $contacts['imported']],
            'messages' => ['received' => $messages['received'], 'imported' => $messages['imported']],
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
        // A chat's messages arrive together: look its contact up once per batch, not once per message.
        $cacheKey = $threadUser ?? ($identity->waId ?? $identity->bsuid ?? null);
        $contact = $cacheKey !== null && isset($this->contactCache[$cacheKey])
            ? $this->contactCache[$cacheKey]
            : $this->contacts->resolve($identity, 'history');
        if ($contact !== null) {
            if ($cacheKey !== null) {
                $this->contactCache[$cacheKey] = $contact;
            }
            $this->recorder->record($number, $contact, $payload, MessageOrigin::History);
        }
    }
}
