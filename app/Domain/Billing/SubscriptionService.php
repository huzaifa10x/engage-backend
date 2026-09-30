<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Enums\BillingProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\SubscriptionEvent;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Owns internal subscription state. Payment providers (Phase 6) call into this service from
 * idempotent webhook handlers; nothing else writes to `subscriptions`.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function startTrial(Tenant $tenant): Subscription
    {
        $version = Plan::byKey((string) config('engage.plans.trial_plan'))->activeVersionOrFail();
        $days = (int) config('engage.plans.trial_days', $version->trial_days ?: 14);

        return $this->assign($tenant, $version, SubscriptionStatus::Trialing, now()->addDays($days), 'trial_started');
    }

    /** Replace the tenant's live subscription (if any) with a new one on $version. */
    public function assign(
        Tenant $tenant,
        PlanVersion $version,
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?CarbonInterface $trialEndsAt = null,
        string $reason = 'plan_assigned',
        BillingProvider $provider = BillingProvider::Manual,
    ): Subscription {
        $membership = $this->context->tenantOrNull()?->getKey() === $tenant->getKey() ? $this->context->membership() : null;

        $subscription = DB::transaction(fn () => $this->context->run($tenant, function (Tenant $tenant) use ($version, $status, $trialEndsAt, $reason, $provider) {
            $current = Subscription::query()->where('tenant_id', $tenant->getKey())->live()->lockForUpdate()->first();

            if ($current !== null) {
                $this->transition($current, SubscriptionStatus::Canceled, 'replaced', ['by_plan_version' => $version->getKey()]);
                $current->forceFill(['canceled_at' => now(), 'ended_at' => now()])->save();
            }

            $subscription = Subscription::query()->create([
                'tenant_id' => $tenant->getKey(),
                'plan_version_id' => $version->getKey(),
                'status' => $status,
                'provider' => $provider,
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => now(),
            ]);

            $this->recordEvent($subscription, $reason, null, $status, ['plan_version_id' => $version->getKey()]);
            $this->audit->record('subscription.'.$reason, $subscription, after: [
                'plan_version_id' => $version->getKey(),
                'status' => $status->value,
            ]);

            return $subscription;
        }, $membership));

        $this->entitlements->forget($tenant);

        return $subscription;
    }

    /** @param array<string, mixed> $payload */
    public function transition(Subscription $subscription, SubscriptionStatus $to, string $type, array $payload = [], ?string $idempotencyKey = null): void
    {
        $from = $subscription->status;

        $subscription->forceFill(['status' => $to])->save();
        $this->recordEvent($subscription, $type, $from, $to, $payload, $idempotencyKey);
    }

    /** Trial ended without a paid plan → downgrade to the fallback (Free) plan. */
    public function expireTrial(Subscription $subscription): void
    {
        $tenant = $this->context->bypass(fn () => Tenant::query()->findOrFail($subscription->tenant_id));

        DB::transaction(function () use ($subscription, $tenant) {
            $this->context->run($tenant, function () use ($subscription) {
                $this->transition($subscription, SubscriptionStatus::Expired, 'trial_expired');
                $subscription->forceFill(['ended_at' => now()])->save();
            });

            $free = Plan::byKey((string) config('engage.plans.fallback'))->activeVersionOrFail();
            $this->assign($tenant, $free, SubscriptionStatus::Active, null, 'downgraded_to_fallback');
        });
    }

    /** @param array<string, mixed> $payload */
    private function recordEvent(Subscription $subscription, string $type, ?SubscriptionStatus $from, SubscriptionStatus $to, array $payload = [], ?string $idempotencyKey = null): void
    {
        SubscriptionEvent::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->getKey(),
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
    }
}
