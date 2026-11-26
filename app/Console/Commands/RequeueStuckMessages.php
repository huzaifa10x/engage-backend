<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Jobs\SendWhatsappMessage;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every five minutes: a reply still "sending" after 10 minutes lost its job (a worker restarted or
 * died mid-send). It is queued again; the job's per-message lock and status check mean it can never
 * go out twice. After 6 hours it is failed with Retry offered, so nobody waits on a clock forever.
 * Campaign messages are left to the campaign's own pacing.
 */
final class RequeueStuckMessages extends Command
{
    protected $signature = 'engage:messages:requeue-stuck';

    protected $description = 'Queue again, or fail with Retry, replies stuck in sending.';

    public const STUCK_AFTER_MINUTES = 10;

    public const GIVE_UP_AFTER_HOURS = 6;

    public function handle(TenantContext $context): int
    {
        $stuck = $context->bypass(fn () => Message::query()
            ->where('status', MessageStatus::Queued->value)
            ->whereNull('campaign_id')
            ->whereIn('origin', [MessageOrigin::Agent->value, MessageOrigin::Api->value, MessageOrigin::Automation->value])
            ->where('updated_at', '<=', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->orderBy('updated_at')->limit(500)->get());

        $requeued = $failed = 0;
        foreach ($stuck->groupBy('tenant_id') as $tenantId => $messages) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($messages, &$requeued, &$failed): void {
                foreach ($messages as $message) {
                    if ($message->updated_at !== null && $message->updated_at->lte(now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
                        SendWhatsappMessage::markFailed($message, 'send_stuck', 'Sending did not finish in time.');
                        $failed++;

                        continue;
                    }
                    $message->touch(); // checked again in 10 minutes, not on every run
                    SendWhatsappMessage::dispatch($message->id, $message->phone_number_id);
                    $requeued++;
                }
            });
        }
        if ($requeued + $failed > 0) {
            $this->info("Queued again: {$requeued}. Failed with Retry: {$failed}.");
        }

        return self::SUCCESS;
    }
}
