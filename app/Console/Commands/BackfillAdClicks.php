<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversions\Services\AdClicks;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Conversions to Meta: fills ad_clicks from the ad referrals already stored on incoming messages.
 * Only messages whose referral carries the raw click ID (ctwa_clid) are used. Safe to run again:
 * a message already saved as a click is skipped.
 */
final class BackfillAdClicks extends Command
{
    protected $signature = 'engage:conversions:backfill-clicks {--workspace= : Only this workspace (ID)}';

    protected $description = 'Save ad clicks (ctwa_clid) from the referrals already stored on incoming messages.';

    public function handle(TenantContext $context, AdClicks $clicks): int
    {
        $saved = 0;
        $seen = 0;
        $context->bypass(function () use ($clicks, &$saved, &$seen) {
            Message::query()->where('direction', Message::INBOUND)
                ->whereRaw("jsonb_typeof(content->'referral') = 'object'")
                ->whereRaw("coalesce(content->'referral'->>'ctwa_clid', '') <> ''")
                ->when($this->option('workspace'), fn ($q, $id) => $q->where('tenant_id', $id))
                ->whereNotExists(fn ($q) => $q->from('ad_clicks')->whereColumn('ad_clicks.message_id', 'messages.id'))
                ->orderBy('created_at')
                ->chunkById(500, function ($messages) use ($clicks, &$saved, &$seen) {
                    foreach ($messages as $message) {
                        $seen++;
                        $saved += $clicks->record($message) ? 1 : 0;
                    }
                });
        });
        $this->info("Messages with a click ID: {$seen}. Ad clicks saved: {$saved}.");

        return self::SUCCESS;
    }
}
