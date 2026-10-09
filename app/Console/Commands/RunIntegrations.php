<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Services\CommerceEvents;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every minute: abandoned-checkout reminders whose waiting time has passed are sent (unless the
 * customer completed the order in the meantime, which cancels the reminder when the order arrives).
 * Also keeps the activity log to its last 60 days.
 */
final class RunIntegrations extends Command
{
    private const KEEP_DAYS = 60;

    protected $signature = 'engage:integrations:run';

    protected $description = 'Send store messages that were waiting (abandoned checkouts) and trim the integrations activity log.';

    public function handle(TenantContext $context, CommerceEvents $events): int
    {
        $due = $context->bypass(fn () => IntegrationEvent::query()->where('status', 'pending')->whereNotNull('due_at')->where('due_at', '<=', now())->orderBy('due_at')->limit(300)->get());

        foreach ($due->groupBy('tenant_id') as $tenantId => $waiting) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($waiting, $events): void {
                foreach ($waiting as $event) {
                    $integration = Integration::query()->find($event->integration_id);
                    if ($integration !== null) {
                        $events->deliver($integration, $event->refresh());
                    }
                }
            });
        }

        $context->bypass(fn () => IntegrationEvent::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->where('status', '!=', 'pending')->limit(5000)->delete());
        $this->info("Processed {$due->count()} waiting store event(s).");

        return self::SUCCESS;
    }
}
