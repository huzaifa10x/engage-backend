<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Jobs\LaunchCampaign;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Starts campaigns whose scheduled time has arrived (runs every minute). */
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

        $this->components->info("Started {$started} scheduled campaign(s).");

        return self::SUCCESS;
    }
}
