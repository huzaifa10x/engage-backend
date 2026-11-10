<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Automation\FollowUps;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Every few minutes: chats that need a reply have their follow-up alerts (see FollowUps::sweep). */
final class SweepFollowUps extends Command
{
    protected $signature = 'engage:followups:sweep';

    protected $description = 'Make sure every chat that needs a reply has the team alerts of its current wait.';

    public function handle(TenantContext $context): int
    {
        $checked = 0;
        $tenants = $context->bypass(fn () => Tenant::query()->whereIn('id', fn ($q) => $q->select('tenant_id')->from('conversations')
            ->where('reply_state', 'needs_reply')->where('needs_reply_since', '>=', now()->subDay()))->get());
        foreach ($tenants as $tenant) {
            $checked += $context->run($tenant, fn () => FollowUps::sweep());
        }
        $this->components->info("Follow-ups: {$checked} waiting chat(s) checked.");

        return self::SUCCESS;
    }
}
