<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\ManageEntitlementOverride;
use App\Application\Admin\ManageTenant;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\SubscriptionEvent;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Enums\PlanVersionStatus;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Plans\Models\PlanVersionFeature;
use App\Domain\Plans\Models\TenantEntitlementOverride;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'closed', 'trialing'])],
            'plan' => ['nullable', 'string', 'max:32'],
        ]);

        $companies = Tenant::query()
            ->with('liveSubscription.planVersion.plan')
            ->withCount('memberships')
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('name', 'ilike', "%{$term}%")->orWhere('slug', 'ilike', "%{$term}%")->orWhere('billing_email', 'ilike', "%{$term}%")))
            ->when(($filters['status'] ?? null) === 'trialing', fn (Builder $q) => $q->whereHas('liveSubscription', fn ($s) => $s->where('status', SubscriptionStatus::Trialing)))
            ->when(in_array($filters['status'] ?? null, ['active', 'suspended', 'closed'], true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['plan'] ?? null, function (Builder $q, string $plan) {
                $onPlan = fn ($s) => $s->whereHas('planVersion.plan', fn ($p) => $p->where('key', $plan));

                return $plan === 'free'
                    ? $q->where(fn ($w) => $w->whereDoesntHave('liveSubscription')->orWhereHas('liveSubscription', $onPlan))
                    : $q->whereHas('liveSubscription', $onPlan);
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'status' => $t->status->value,
                'plan' => $t->liveSubscription?->planVersion?->plan?->name ?? 'Free',
                'subscription_status' => $t->liveSubscription?->status->value,
                'members' => $t->getAttribute('memberships_count'),
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('companies/Index', [
            'companies' => $companies,
            'filters' => (object) $filters,
            'plans' => $this->planOptions(),
        ]);
    }

    public function show(Tenant $tenant, EntitlementService $entitlements): Response
    {
        $tenant->load('liveSubscription.planVersion.plan');
        $subscription = $tenant->liveSubscription;

        $members = TenantMembership::query()->where('tenant_id', $tenant->id)
            ->with(['user', 'role'])->orderBy('created_at')->get()
            ->map(fn (TenantMembership $m) => [
                'id' => $m->id,
                'status' => $m->status->value,
                'role' => $m->role?->name,
                'role_key' => $m->role?->key,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'last_login_at' => $m->user?->getAttribute('last_login_at')?->toIso8601String(),
                'disabled' => $m->user?->isDisabled() ?? false,
            ]);

        $set = $entitlements->for($tenant);
        $planValues = PlanVersionFeature::query()->where('plan_version_id', $set->planVersionId)->with('feature')->get()
            ->keyBy(fn (PlanVersionFeature $f) => $f->feature?->key);
        $overrides = TenantEntitlementOverride::query()->where('tenant_id', $tenant->id)->with('feature')->get()
            ->keyBy(fn (TenantEntitlementOverride $o) => $o->feature?->key);

        $features = collect(FeatureKey::cases())->map(function (FeatureKey $key) use ($set, $planValues, $overrides) {
            $plan = $planValues->get($key->value);
            $override = $overrides->get($key->value);
            $effective = $set->get($key);

            return [
                'key' => $key->value,
                'label' => $key->label(),
                'type' => $key->type()->value,
                'unit' => $key->unit(),
                'plan' => ['enabled' => (bool) $plan?->enabled, 'limit' => $plan?->limit_value, 'config' => (object) ($plan?->config ?? [])],
                'override' => $override ? [
                    'enabled' => $override->enabled,
                    'limit' => $override->limit_value,
                    'unlimited' => (bool) $override->getAttribute('unlimited'),
                    'reason' => $override->getAttribute('reason'),
                    'expires_at' => $override->getAttribute('expires_at')?->toIso8601String(),
                ] : null,
                'effective' => $effective->toResponseArray(),
            ];
        })->values();

        $events = $subscription === null ? [] : SubscriptionEvent::query()->where('tenant_id', $tenant->id)
            ->orderByDesc('occurred_at')->limit(10)->get(['type', 'from_status', 'to_status', 'occurred_at'])
            ->map(fn ($e) => $e->only(['type', 'from_status', 'to_status']) + ['occurred_at' => $e->getAttribute('occurred_at')?->toIso8601String()]);

        $audit = AuditLog::query()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->limit(15)->get()
            ->map(fn (AuditLog $a) => [
                'id' => $a->id, 'action' => $a->getAttribute('action'), 'actor_type' => $a->getAttribute('actor_type'),
                'entity_type' => $a->getAttribute('entity_type'), 'created_at' => $a->getAttribute('created_at')?->toIso8601String(),
            ]);

        return Inertia::render('companies/Show', [
            'company' => [
                'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status->value,
                'timezone' => $tenant->timezone, 'currency' => $tenant->currency, 'country' => $tenant->country,
                'billing_email' => $tenant->billing_email, 'created_at' => $tenant->created_at?->toIso8601String(),
                'suspended_at' => $tenant->getAttribute('suspended_at')?->toIso8601String(),
            ],
            'subscription' => $subscription === null ? null : [
                'plan' => $subscription->planVersion?->plan?->name,
                'plan_key' => $subscription->planVersion?->plan?->key,
                'version' => $subscription->planVersion?->version,
                'status' => $subscription->status->value,
                'price_monthly_minor' => $subscription->planVersion?->price_monthly_minor,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'provider' => $subscription->provider->value,
            ],
            'effectivePlan' => ['key' => $set->planKey, 'name' => $set->planName],
            'subscriptionEvents' => $events,
            'members' => $members,
            'features' => $features,
            'audit' => $audit,
            'planVersions' => $this->planOptions(),
        ]);
    }

    public function updateStatus(Request $request, Tenant $tenant, ManageTenant $manage): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TenantStatus::class)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $manage->setStatus($tenant, TenantStatus::from($data['status']), $data['reason']);

        return back()->with('success', $data['status'] === 'suspended' ? 'Company suspended.' : 'Company status updated.');
    }

    public function updatePlan(Request $request, Tenant $tenant, ManageTenant $manage): RedirectResponse
    {
        $data = $request->validate([
            'plan_version_id' => ['required', 'uuid', Rule::exists('plan_versions', 'id')->where('status', PlanVersionStatus::Active->value)],
            'status' => ['required', Rule::in(['active', 'trialing'])],
            'trial_days' => ['nullable', 'required_if:status,trialing', 'integer', 'min:1', 'max:90'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $manage->changePlan($tenant, PlanVersion::query()->findOrFail($data['plan_version_id']),
            SubscriptionStatus::from($data['status']), $data['trial_days'] ?? null, $data['reason']);

        return back()->with('success', 'Plan changed.');
    }

    public function extendTrial(Request $request, Tenant $tenant, ManageTenant $manage): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:60'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $manage->extendTrial($tenant, (int) $data['days'], $data['reason']);

        return back()->with('success', "Trial extended by {$data['days']} days.");
    }

    public function setOverride(Request $request, Tenant $tenant, string $feature, ManageEntitlementOverride $overrides): RedirectResponse
    {
        $key = FeatureKey::tryFrom($feature) ?? abort(404);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'unlimited' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var PlatformAdmin $admin */
        $admin = $request->user('admin');

        $overrides->set($tenant, $key, [
            'enabled' => $data['enabled'] ?? null,
            'limit' => isset($data['limit']) ? (int) $data['limit'] : null,
            'unlimited' => (bool) ($data['unlimited'] ?? false),
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            'reason' => $data['reason'],
        ], $admin);

        return back()->with('success', "{$key->label()} override saved.");
    }

    public function removeOverride(Request $request, Tenant $tenant, string $feature, ManageEntitlementOverride $overrides): RedirectResponse
    {
        $key = FeatureKey::tryFrom($feature) ?? abort(404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $overrides->remove($tenant, $key, $data['reason']);

        return back()->with('success', "{$key->label()} is back to the plan value.");
    }

    /** @return list<array{id: string, label: string, plan_key: string}> */
    private function planOptions(): array
    {
        return PlanVersion::query()->where('status', PlanVersionStatus::Active)->with('plan')->get()
            ->sortBy(fn (PlanVersion $v) => $v->plan?->getAttribute('sort_order'))
            ->map(fn (PlanVersion $v) => [
                'id' => $v->id,
                'plan_key' => (string) $v->plan?->key,
                'label' => sprintf('%s (v%d, %s)', $v->plan?->name, $v->version,
                    $v->price_monthly_minor === null ? 'Custom' : '$'.number_format($v->price_monthly_minor / 100).'/mo'),
            ])->values()->all();
    }
}
