<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Hourly housekeeping for coexistence imports, so a number never shows "syncing" forever:
 *
 *  • the sync was never requested and Meta's 24-hour window has closed → "sync failed"
 *    (the import can no longer be started; the number itself keeps working);
 *  • the sync was requested but no data has arrived for a long time (phone offline, or the
 *    owner declined) → closed as "synced" with whatever was imported.
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

                $lastActivity = $jobs->max('updated_at') ?? $number->getAttribute('app_sync_started_at');
                if ($lastActivity !== null && $lastActivity->lte(now()->subHours($staleHours))) {
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
