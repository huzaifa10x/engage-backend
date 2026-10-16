<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messaging\Services\MessagingLimits;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\Jobs\RefreshPhoneNumber;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Hourly safety net behind Meta's limit webhooks: re-reads every connected number's daily
 * messaging limit, quality and throughput from Meta (one small Graph call per number, queued),
 * so a raised limit is used within the hour even if the webhook was missed. Also removes
 * counted conversations that left the 24-hour window long ago.
 */
final class RefreshMessagingLimits extends Command
{
    protected $signature = 'engage:whatsapp:refresh-limits';

    protected $description = 'Re-read the daily messaging limit of every connected WhatsApp number from Meta.';

    public function handle(TenantContext $context): int
    {
        $ids = $context->bypass(fn () => PhoneNumber::query()->where('status', PhoneNumberStatus::Connected)->pluck('id'));
        foreach ($ids as $id) {
            RefreshPhoneNumber::dispatch((string) $id);
        }
        $pruned = $context->bypass(fn () => MessagingLimits::prune());

        $this->components->info("Queued a limit refresh for {$ids->count()} number(s); removed {$pruned} expired count(s).");

        return self::SUCCESS;
    }
}
