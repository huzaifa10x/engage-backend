<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Billing\ManagePlans;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Plans\Models\PlanVersionFeature;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Super Admin → Plans: pricing, intervals, limits, rate limits, feature access, on/off. */
final class PlanController extends Controller
{
    public function __construct(private readonly ManagePlans $plans, private readonly TenantContext $context) {}

    public function index(): Response
    {
        $planOf = PlanVersion::query()->pluck('plan_id', 'id');
        $subscribers = [];
        foreach ($this->context->bypass(fn () => Subscription::query()->live()->selectRaw('plan_version_id, count(*) AS total')->groupBy('plan_version_id')->toBase()->pluck('total', 'plan_version_id')) as $versionId => $total) {
            $planId = (string) ($planOf[$versionId] ?? '');
            $subscribers[$planId] = ($subscribers[$planId] ?? 0) + (int) $total;
        }

        return Inertia::render('plans/Index', [
            'plans' => Plan::query()->orderBy('sort_order')->get()->map(function (Plan $plan) use ($subscribers) {
                $version = $plan->activeVersion();

                return [
                    'id' => $plan->id,
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'is_public' => $plan->is_public,
                    'is_active' => $plan->is_active,
                    'version' => $version?->version,
                    'price_monthly_minor' => $version?->price_monthly_minor,
                    'price_yearly_minor' => $version?->price_yearly_minor,
                    'subscribers' => (int) ($subscribers[$plan->id] ?? 0),
                ];
            }),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('plans/Edit', ['plan' => null, 'features' => $this->features(null)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->input($request);
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('plans', 'key')]])['key'];

        $plan = $this->plans->create($key, $data);

        return redirect()->route('admin.plans.edit', $plan)->with('success', "Plan “{$plan->name}” created.");
    }

    public function edit(Plan $plan): Response
    {
        $version = $plan->activeVersion();

        return Inertia::render('plans/Edit', [
            'plan' => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'description' => $plan->description,
                'is_public' => $plan->is_public,
                'is_active' => $plan->is_active,
                'sort_order' => $plan->sort_order,
                'version' => $version?->version,
                'price_monthly_minor' => $version?->price_monthly_minor,
                'price_yearly_minor' => $version?->price_yearly_minor,
                'trial_days' => $version->trial_days ?? 0,
                'subscribers' => $this->context->bypass(fn () => Subscription::query()->live()->whereIn('plan_version_id', $plan->versions()->pluck('id'))->count()),
            ],
            'features' => $this->features($version?->id),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $result = $this->plans->update($plan, $this->input($request), $request->boolean('apply_to_subscribers'));

        return redirect()->route('admin.plans.edit', $plan)->with('success', "Version {$result['version']} published."
            .($result['moved'] > 0 ? " {$result['moved']} subscriber(s) moved to the new limits and features." : ''));
    }

    public function toggle(Request $request, Plan $plan): RedirectResponse
    {
        $active = $request->boolean('active');
        if (! $active && $plan->key === config('engage.plans.fallback')) {
            throw ValidationException::withMessages(['active' => 'The fallback plan (Free) cannot be deactivated: workspaces without a paid plan run on it.']);
        }
        $this->plans->setActive($plan, $active);

        return back()->with('success', $active ? "“{$plan->name}” can be bought again." : "“{$plan->name}” is no longer offered. Existing subscribers keep it.");
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Prices in USD minor units (cents). Empty = custom pricing (sold by the sales team).
            'price_monthly_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_yearly_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'features' => ['required', 'array'],
            'features.*.enabled' => ['nullable', 'boolean'],
            'features.*.unlimited' => ['nullable', 'boolean'],
            'features.*.limit' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'features.*.config' => ['nullable', 'array'],
        ]);
        $data['features'] = array_intersect_key($data['features'], array_flip(array_map(fn (FeatureKey $k) => $k->value, FeatureKey::cases())));

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function features(?string $versionId): array
    {
        $values = $versionId === null ? collect() : PlanVersionFeature::query()->where('plan_version_id', $versionId)->with('feature')->get()
            ->keyBy(fn (PlanVersionFeature $f) => $f->feature?->key);

        return array_map(function (FeatureKey $key) use ($values) {
            $value = $values->get($key->value);
            $quantified = $key->type()->isQuantified();

            return [
                'key' => $key->value,
                'label' => $key->label(),
                'type' => $key->type()->value,
                'unit' => $key->unit(),
                'enabled' => (bool) ($value->enabled ?? false),
                'limit' => $value?->limit_value,
                'unlimited' => $quantified && $value !== null && $value->enabled && $value->limit_value === null,
                'config' => (object) ($value->config ?? []),
            ];
        }, FeatureKey::cases());
    }
}
