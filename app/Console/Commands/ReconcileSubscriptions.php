<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Billing\StripeBilling;
use App\Domain\Billing\Enums\BillingProvider;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net behind Stripe's webhooks: any paid subscription whose period or cancellation date
 * has passed is re-read from Stripe. If Stripe says it has ended, the workspace moves to the
 * Free plan — even if the webhook never arrived.
 */
final class ReconcileSubscriptions extends Command
{
    protected $signature = 'engage:billing:reconcile';

    protected $description = 'Re-check paid subscriptions that should have renewed or ended, and move ended ones to the Free plan.';

    public function handle(TenantContext $context, StripeClient $stripe, StripeBilling $billing): int
    {
        if (! $stripe->configured()) {
            return self::SUCCESS;
        }

        $due = $context->bypass(fn () => Subscription::query()->live()
            ->where('provider', BillingProvider::Stripe->value)->whereNotNull('provider_subscription_id')
            ->where(fn ($q) => $q->where('cancel_at', '<=', now())->orWhere('current_period_end', '<=', now()->subHour()))
            ->limit(500)->get());

        $checked = 0;
        foreach ($due as $subscription) {
            try {
                $billing->syncSubscription($stripe->get('subscriptions/'.$subscription->provider_subscription_id));
                $checked++;
            } catch (StripeException $e) {
                if ($e->stripeCode === 'resource_missing') {
                    // Gone on Stripe (deleted, or left over from the other Stripe mode): nothing is being paid for.
                    $tenant = $context->bypass(fn () => Tenant::query()->find($subscription->tenant_id));
                    if ($tenant !== null) {
                        $billing->endSubscription($tenant, (string) $subscription->provider_subscription_id);
                        $checked++;
                    }

                    continue;
                }
                Log::warning('Subscription reconcile failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }

        $this->components->info("Checked {$checked} subscription(s).");

        return self::SUCCESS;
    }
}
