<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Campaigns\ManageCampaigns;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Exceptions\CampaignException;
use App\Domain\Campaigns\Jobs\LaunchCampaign;
use App\Domain\Campaigns\Jobs\SendCampaignBatch;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Services\AbTest;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Every minute: starts scheduled campaigns that are due, and continues campaigns waiting for quiet hours or a drip batch. */
final class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'engage:campaigns:dispatch';

    protected $description = 'Start scheduled WhatsApp campaigns that are due.';

    public function handle(TenantContext $context): int
    {
        $due = $context->bypass(fn () => Campaign::query()->where('status', CampaignStatus::Scheduled->value)->where('scheduled_at', '<=', now())->limit(200)->get());
        $started = 0;

        foreach ($due as $campaign) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($campaign->tenant_id));
            if ($tenant === null) {
                continue;
            }
            // A statement inside the closure: the job must be pushed while the tenant context is set.
            $context->run($tenant, function () use ($campaign, &$started): void {
                $claimed = Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled->value)
                    ->update(['status' => CampaignStatus::Sending->value, 'started_at' => now()]);
                if ($claimed === 1) {
                    LaunchCampaign::dispatch($campaign->id);
                    $started++;
                }
            });
        }

        // Campaigns waiting for quiet hours to end or for their next drip batch.
        $waiting = $context->bypass(fn () => Campaign::query()->where('status', CampaignStatus::Sending->value)->whereNotNull('next_batch_at')->where('next_batch_at', '<=', now())->limit(500)->get());
        $continued = 0;
        foreach ($waiting as $campaign) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($campaign->tenant_id));
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($campaign, &$continued): void {
                $claimed = Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)->whereNotNull('next_batch_at')->update(['next_batch_at' => null, 'wait_reason' => null]);
                if ($claimed === 1) {
                    SendCampaignBatch::dispatch($campaign->id);
                    $continued++;
                }
            });
        }

        // A/B tests on 20% of the list with "send the winner automatically": once the test is sent and a
        // winner is clear, the other 80% receive it. Without a clear winner they keep waiting.
        $tests = $context->bypass(fn () => Campaign::query()->where('status', CampaignStatus::Completed->value)
            ->where('completed_at', '>=', now()->subDays(14))
            ->whereRaw("(ab_test->>'auto_send_winner')::boolean IS TRUE AND (ab_test->'rest' IS NULL OR ab_test->'rest' = 'null'::jsonb)")
            ->limit(100)->get());
        $winners = 0;
        foreach ($tests as $campaign) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($campaign->tenant_id));
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($campaign, &$winners): void {
                $winner = app(AbTest::class)->results($campaign)['winner'];
                if ($winner !== null) {
                    try {
                        app(ManageCampaigns::class)->sendRest($campaign, $winner, auto: true);
                        $winners++;
                    } catch (CampaignException) {
                        // The number was disconnected: the owner can send it from the campaign later.
                    }
                }
            });
        }

        $this->components->info("Started {$started} scheduled campaign(s); continued {$continued} waiting campaign(s); sent {$winners} A/B winner(s) to the rest of the list.");

        return self::SUCCESS;
    }
}
