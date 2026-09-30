<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Super Admin lifecycle operations on a company. Every change is audited on the tenant. */
final class ManageTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionService $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function setStatus(Tenant $tenant, TenantStatus $status, string $reason): void
    {
        $before = $tenant->status;
        if ($before === $status) {
            return;
        }

        $this->context->bypass(fn () => DB::transaction(function () use ($tenant, $status, $before, $reason) {
            $tenant->forceFill([
                'status' => $status,
                'suspended_at' => $status === TenantStatus::Suspended ? now() : null,
            ])->save();

            $this->audit->record('tenant.status_changed', $tenant,
                before: ['status' => $before->value], after: ['status' => $status->value],
                meta: ['reason' => $reason], tenantId: $tenant->getKey());
        }));
    }

    public function changePlan(Tenant $tenant, PlanVersion $version, SubscriptionStatus $status, ?int $trialDays, string $reason): Subscription
    {
        if (! in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            throw ValidationException::withMessages(['status' => 'A plan can be assigned as active or trialing.']);
        }

        $trialEndsAt = $status === SubscriptionStatus::Trialing ? now()->addDays(max(1, $trialDays ?? 14)) : null;

        return DB::transaction(function () use ($tenant, $version, $status, $trialEndsAt, $reason) {
            $subscription = $this->subscriptions->assign($tenant, $version, $status, $trialEndsAt, 'plan_assigned_by_admin');

            $this->context->bypass(fn () => $this->audit->record('tenant.plan_changed', $tenant,
                after: ['plan_version_id' => $version->getKey(), 'status' => $status->value],
                meta: ['reason' => $reason], tenantId: $tenant->getKey()));

            return $subscription;
        });
    }

    public function extendTrial(Tenant $tenant, int $days, string $reason): void
    {
        $this->context->bypass(fn () => DB::transaction(function () use ($tenant, $days, $reason) {
            /** @var Subscription|null $subscription */
            $subscription = $tenant->liveSubscription()->lockForUpdate()->first();

            if ($subscription === null || $subscription->status !== SubscriptionStatus::Trialing) {
                throw ValidationException::withMessages(['days' => 'This company is not on a trial.']);
            }

            $before = $subscription->trial_ends_at;
            $after = ($before !== null && $before->isFuture() ? $before : now())->copy()->addDays($days);
            $subscription->forceFill(['trial_ends_at' => $after])->save();

            $this->audit->record('subscription.trial_extended', $subscription,
                before: ['trial_ends_at' => $before?->toIso8601String()], after: ['trial_ends_at' => $after->toIso8601String()],
                meta: ['reason' => $reason, 'days' => $days], tenantId: $tenant->getKey());
        }));

        $this->entitlements->forget($tenant);
    }
}
