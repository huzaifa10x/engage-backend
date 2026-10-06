<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Every minute: snoozed conversations whose time has come return to the inbox, and the assignee is told. */
final class WakeSnoozedConversations extends Command
{
    protected $signature = 'engage:inbox:wake-snoozed';

    protected $description = 'Return snoozed conversations to the inbox when their snooze time has passed.';

    public function handle(TenantContext $context, InboxTools $tools): int
    {
        $due = $context->bypass(fn () => Conversation::query()->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now())->limit(500)->get());

        foreach ($due->groupBy('tenant_id') as $tenantId => $conversations) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($conversations, $tools): void {
                foreach ($conversations as $conversation) {
                    if (Conversation::query()->whereKey($conversation->id)->whereNotNull('snoozed_until')->update(['snoozed_until' => null]) !== 1) {
                        continue;
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
