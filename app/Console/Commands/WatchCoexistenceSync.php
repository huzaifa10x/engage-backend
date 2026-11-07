<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Services\CoexistenceImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly housekeeping for coexistence imports, so a number never shows "syncing" forever:
 *
 *  • the sync was never requested and Meta's 24-hour window has closed → "sync failed"
 *    (the import can no longer be started; the number itself keeps working);
 *  • the window has closed, WhatsApp has sent nothing for 30 minutes and everything that arrived is
 *    imported (WhatsApp stopped part-way, usually the phone's app) → closed as "synced" with what it
 *    shared; it re-opens by itself if WhatsApp resumes sending;
 *  • no activity at all for a long time → closed the same way.
 */
final class WatchCoexistenceSync extends Command
{
    protected $signature = 'engage:coexistence:watch';

    protected $description = 'Close coexistence history imports that missed the 24-hour window or went quiet.';

    public function handle(TenantContext $context): int
    {
        $staleHours = max(1, (int) config('engage.meta.coexistence_sync_stale_hours', 72));
        $closed = $failed = 0;

        $context->bypass(function () use ($staleHours, &$closed, &$failed): void {
            $numbers = PhoneNumber::query()
                ->whereIn('coexistence_status', [CoexistenceStatus::SyncPending->value, CoexistenceStatus::HistorySyncing->value])
                ->whereNotNull('app_sync_expires_at')->where('app_sync_expires_at', '<=', now())->get();

            foreach ($numbers as $number) {
                $jobs = CoexistenceSyncJob::query()->where('phone_number_id', $number->id)->get();
                $requested = $jobs->contains(fn (CoexistenceSyncJob $j) => $j->getAttribute('request_id') !== null);

                if (! $requested) {
                    $number->forceFill(['coexistence_status' => CoexistenceStatus::SyncFailed])->save();
                    $jobs->each(fn (CoexistenceSyncJob $j) => $j->forceFill(['status' => 'failed', 'error_message' => 'Not started within 24 hours of onboarding.', 'completed_at' => now()])->save());
                    $failed++;

                    continue;
                }

                // Meta's window is over. Once WhatsApp has been quiet for a while and everything that
                // arrived is imported, close it with what WhatsApp shared (it re-opens if WhatsApp resumes).
                $lastReceived = $jobs->max('last_received_at');
                $waiting = DB::table('coexistence_sync_items')->where('phone_number_id', $number->id)->exists();
                $quietSinceWindow = $lastReceived !== null && $lastReceived->lte(now()->subMinutes(CoexistenceImport::PAUSED_AFTER_MINUTES));
                $lastActivity = $jobs->max('updated_at') ?? $number->getAttribute('app_sync_started_at');
                $stale = $lastActivity !== null && $lastActivity->lte(now()->subHours($staleHours));
                if (($quietSinceWindow && ! $waiting) || $stale) {
                    $number->forceFill(['coexistence_status' => CoexistenceStatus::Synced])->save();
                    $jobs->filter(fn (CoexistenceSyncJob $j) => $j->getAttribute('completed_at') === null)
                        ->each(fn (CoexistenceSyncJob $j) => $j->forceFill(['status' => 'completed', 'completed_at' => now()])->save());
                    $closed++;
                }
            }
        });

        $this->components->info("Coexistence imports: {$closed} closed after going quiet, {$failed} missed the 24-hour window.");

        return self::SUCCESS;
    }
}
