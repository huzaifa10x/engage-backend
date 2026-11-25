<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Billing\StripeBilling;
use App\Application\Notifications\WorkspaceMailer;
use App\Domain\Billing\Enums\BillingProvider;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use App\Notifications\BillingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Batch 6: emails the owner (and billing email) 30 and 7 days before the card expires, and 7 days
 * before an annual renewal. The card is read from Stripe each time; each reminder is sent once.
 */
final class SendBillingReminders extends Command
{
    protected $signature = 'engage:billing:reminders';

    protected $description = 'Email card-expiry (30 and 7 days) and annual-renewal (7 days) reminders for paid plans.';

    public function handle(TenantContext $context, StripeClient $stripe, StripeBilling $billing, WorkspaceMailer $mailer): int
    {
        if (! $stripe->configured()) {
            return self::SUCCESS;
        }
        $today = now()->startOfDay();
        $sent = 0;

        $context->bypass(fn () => Subscription::query()->live()
            ->where('provider', BillingProvider::Stripe->value)->whereNotNull('provider_subscription_id')->whereNull('cancel_at')
            ->orderBy('id')->chunkById(200, function ($subscriptions) use ($context, $billing, $mailer, $today, &$sent): void {
                foreach ($subscriptions as $subscription) {
                    $tenant = $context->bypass(fn () => Tenant::query()->find($subscription->tenant_id));
                    if ($tenant === null) {
                        continue;
                    }
                    $workspace = $tenant->legal_name ?: $tenant->name;

                    // Annual renewal in 7 days.
                    $renews = $subscription->current_period_end;
                    if ($subscription->billing_interval === 'yearly' && $renews !== null && (int) $today->diffInDays($renews->copy()->startOfDay(), false) === 7
                        && Cache::add("billing-reminder:renewal:{$subscription->id}:{$renews->toDateString()}", 1, now()->addDays(30))) {
                        $mailer->toBilling($tenant, new BillingReminderNotification(BillingReminderNotification::ANNUAL_RENEWAL, $workspace, $renews->format('j F Y')));
                        $sent++;
                    }

                    // Card expiry: 30 days and 7 days before the end of the expiry month.
                    try {
                        $card = $billing->paymentMethod($tenant);
                    } catch (StripeException $e) {
                        Log::warning('Billing reminder: card not readable', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);

                        continue;
                    }
                    if ($card === null || $card['exp_year'] === 0) {
                        continue;
                    }
                    $expires = Carbon::create($card['exp_year'], $card['exp_month'], 1)->endOfMonth()->startOfDay();
                    $days = (int) $today->diffInDays($expires, false);
                    $step = match (true) {
                        $days >= 0 && $days <= 7 => 7,
                        $days > 7 && $days <= 30 => 30,
                        default => null,
                    };
                    $key = "billing-reminder:card:{$tenant->id}:{$card['last4']}:{$card['exp_month']}-{$card['exp_year']}:{$step}";
                    if ($step !== null && Cache::add($key, 1, now()->addDays(60))) {
                        $mailer->toBilling($tenant, new BillingReminderNotification(BillingReminderNotification::CARD_EXPIRING, $workspace, $expires->format('F Y'), ucfirst($card['brand']).' ending '.$card['last4']));
                        $sent++;
                    }
                }
            }));

        $this->components->info("Sent {$sent} billing reminder(s).");

        return self::SUCCESS;
    }
}
