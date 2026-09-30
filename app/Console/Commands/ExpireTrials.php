<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpireTrials extends Command
{
    protected $signature = 'engage:subscriptions:expire-trials';

    protected $description = 'Downgrade tenants whose trial has ended to the fallback (Free) plan.';

    public function handle(TenantContext $context, SubscriptionService $subscriptions): int
    {
        $expired = 0;

        $context->bypass(fn () => Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->where('trial_ends_at', '<=', now())
            ->lazyById(200)
            ->each(function (Subscription $subscription) use ($subscriptions, &$expired): void {
                try {
                    $subscriptions->expireTrial($subscription);
                    $expired++;
                } catch (Throwable $e) {
                    Log::error('Trial expiry failed', ['subscription_id' => $subscription->getKey(), 'exception' => $e]);
                }
            }));

        $this->components->info("Expired {$expired} trial(s).");

        return self::SUCCESS;
    }
}
