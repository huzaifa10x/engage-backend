<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Growth\GrowthSettings;
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

    /**
     * Growth Hub pilot switches for one workspace (tenants.settings.growth). The first switch-on
     * records when tracking started; contacts created before it stay "Before tracking".
     *
     * @param  array<string, mixed>  $changes
     */
    public function setGrowth(Tenant $tenant, array $changes, string $reason): void
    {
        $this->context->bypass(fn () => DB::transaction(function () use ($tenant, $changes, $reason) {
            $before = (array) (($tenant->settings ?? [])['growth'] ?? []);
            $settings = (array) ($tenant->settings ?? []);
            $settings['growth'] = GrowthSettings::merge($tenant, $changes);
            $tenant->forceFill(['settings' => $settings])->save();
            GrowthSettings::forget();

            $this->audit->record('tenant.growth_changed', $tenant, before: $before, after: $settings['growth'], meta: ['reason' => $reason], tenantId: $tenant->getKey());
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

    /**
     * On a trial: adds days to it. Not on a trial (it ended and the company dropped to Free, or it
     * never had one): starts a new trial of the given plan, or of the plan of its last trial.
     */
    public function extendTrial(Tenant $tenant, int $days, string $reason, ?PlanVersion $plan = null): void
    {
        $extended = $this->context->bypass(fn () => DB::transaction(function () use ($tenant, $days, $reason) {
            /** @var Subscription|null $subscription */
            $subscription = $tenant->liveSubscription()->lockForUpdate()->first();
            if ($subscription === null || $subscription->status !== SubscriptionStatus::Trialing) {
                return false;
            }

            $before = $subscription->trial_ends_at;
            $after = ($before !== null && $before->isFuture() ? $before : now())->copy()->addDays($days);
            $subscription->forceFill(['trial_ends_at' => $after])->save();

            $this->audit->record('subscription.trial_extended', $subscription,
                before: ['trial_ends_at' => $before?->toIso8601String()], after: ['trial_ends_at' => $after->toIso8601String()],
                meta: ['reason' => $reason, 'days' => $days], tenantId: $tenant->getKey());

            return true;
        }));

        if (! $extended) {
            $plan ??= $this->lastTrialPlan($tenant);
            if ($plan === null) {
                throw ValidationException::withMessages(['plan_version_id' => 'Choose the plan for the new trial.']);
            }
            $this->changePlan($tenant, $plan, SubscriptionStatus::Trialing, $days, $reason);
            $this->context->bypass(fn () => $this->audit->record('subscription.trial_restarted', $tenant,
                after: ['plan_version_id' => $plan->getKey(), 'days' => $days], meta: ['reason' => $reason], tenantId: $tenant->getKey()));
        }

        $this->entitlements->forget($tenant);
    }

    /** The plan of the company's most recent trial, if it had one. */
    public function lastTrialPlan(Tenant $tenant): ?PlanVersion
    {
        return $this->context->bypass(function () use ($tenant) {
            /** @var Subscription|null $last */
            $last = Subscription::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())
                ->whereNotNull('trial_ends_at')->orderByDesc('created_at')->first();

            return $last?->planVersion;
        });
    }
}
