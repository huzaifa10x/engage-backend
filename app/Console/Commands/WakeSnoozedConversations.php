<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Automation\FollowUps;
use App\Domain\Automation\Scheduler;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Every minute: runs the scheduler's due actions (snooze returns, the after-hours ledger, later
 * nudges), then returns any snoozed conversation whose time has come and has no scheduled return
 * (snoozed before the scheduler existed) as Needs reply, telling the assignee.
 */
final class WakeSnoozedConversations extends Command
{
    protected $signature = 'engage:inbox:wake-snoozed';

    protected $description = 'Return snoozed conversations to the inbox when their snooze time has passed.';

    public function handle(TenantContext $context, InboxTools $tools, Scheduler $scheduler): int
    {
        $scheduler->runDue();
        $due = $context->bypass(fn () => Conversation::query()->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now())->limit(500)->get());

        foreach ($due->groupBy('tenant_id') as $tenantId => $conversations) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($conversations, $tools): void {
                foreach ($conversations as $conversation) {
                    $back = ReplyState::snoozeEnded($conversation);
                    if (Conversation::query()->whereKey($conversation->id)->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now())->update($back) !== 1) {
                        continue;
                    }
                    // A new wait: its follow-up alerts start now.
                    $fresh = $conversation->fresh();
                    if ($fresh !== null) {
                        $since = Carbon::parse($back['needs_reply_since']);
                        FollowUps::waitStarted($fresh, $since, $fresh->window_expires_at);
                    }
                    $member = $conversation->assigned_membership_id !== null ? TenantMembership::query()->find($conversation->assigned_membership_id) : null;
                    if ($member !== null) {
                        $tools->notify($member, 'snooze_ended', 'A snoozed conversation is back in your inbox', $conversation->contact()->first()?->displayName(), "/inbox?c={$conversation->id}");
                    }
                }
            });
        }

        $this->components->info("Woke {$due->count()} conversation(s).");

        return self::SUCCESS;
    }
}
