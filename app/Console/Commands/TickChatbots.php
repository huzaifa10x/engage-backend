<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Every minute: the chatbot timers. Delay blocks that are due continue, customers who stopped
 * answering get one reminder, and sessions idle past the flow's abandonment time stop (the
 * conversation stays open in the inbox, marked Abandoned, with the step they left at).
 */
final class TickChatbots extends Command
{
    protected $signature = 'engage:chatbots:tick';

    protected $description = 'Run chatbot reminders, abandonment and Delay blocks.';

    public function handle(TenantContext $context, Chatbots $chatbots): int
    {
        $due = $context->bypass(fn () => ChatbotSession::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->where('status', 'waiting')->where('resume_at', '<=', now()))
                // Reminders come after at least a minute; abandonment is checked per flow in tick().
                ->orWhere(fn (Builder $w) => $w->where('status', 'active')->where('last_activity_at', '<=', now()->subMinute())))
            ->orderBy('last_activity_at')->limit(500)->get());

        $counts = [];
        foreach ($due->groupBy('tenant_id') as $tenantId => $sessions) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($sessions, $chatbots, &$counts): void {
                foreach ($sessions as $session) {
                    try {
                        $result = $chatbots->tick($session);
                        if ($result !== null) {
                            $counts[$result] = ($counts[$result] ?? 0) + 1;
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        // Funnel data is kept for 180 days (pruned once an hour, in small batches).
        if (now()->minute === 0) {
            $context->bypass(fn () => ChatbotEvent::query()->where('created_at', '<', now()->subDays(180))->limit(5000)->delete());
        }

        $this->components->info($counts === [] ? 'Nothing due.' : collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
