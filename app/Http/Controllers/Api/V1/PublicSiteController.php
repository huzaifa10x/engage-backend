<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\SalesLead;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersionFeature;
use App\Http\Controllers\Controller;
use App\Notifications\SalesLeadNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * What the public marketing website needs from the product, without signing in:
 * the live plan catalog (so pricing is never hard-coded on the site) and the demo/contact form.
 * Only information that is already public on the pricing page is exposed here.
 */
final class PublicSiteController extends Controller
{
    public const CACHE_KEY = 'public:plan-catalog';

    /** Public, active plans with their published prices, limits and features. Cached for a minute. */
    public function plans(): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, 60, function (): array {
            $plans = Plan::query()->where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get()->map(function (Plan $plan): ?array {
                $version = $plan->activeVersion();
                if ($version === null) {
                    return null;
                }
                $rows = PlanVersionFeature::query()->where('plan_version_id', $version->id)->with('feature')->get()->keyBy(fn (PlanVersionFeature $r) => $r->feature?->key);

                $features = [];
                foreach (FeatureKey::cases() as $key) {
                    $row = $rows[$key->value] ?? null;
                    $type = $key->type()->value;
                    $counted = in_array($type, ['limit', 'metered'], true);
                    $features[] = [
                        'key' => $key->value,
                        'label' => $key->label(),
                        'type' => $type,
                        'unit' => $key->unit(),
                        'enabled' => (bool) ($row->enabled ?? false),
                        // For counted features: a number, or null with unlimited=true.
                        'limit' => $counted && ($row->enabled ?? false) ? $row?->limit_value : null,
                        'unlimited' => $counted && ($row->enabled ?? false) && $row?->limit_value === null,
                        'config' => (object) ($row->config ?? []),
                    ];
                }

                return [
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'currency' => $version->currency,
                    'price_monthly_minor' => $version->price_monthly_minor,
                    'price_yearly_minor' => $version->price_yearly_minor,
                    // No published price: sold through sales ("Talk to us").
                    'custom_price' => $version->price_monthly_minor === null,
                    'free' => $version->price_monthly_minor === 0,
                    'features' => $features,
                ];
            })->filter()->values()->all();

            return [
                'trial' => ['plan_key' => (string) config('engage.plans.trial_plan'), 'days' => (int) config('engage.plans.trial_days')],
                'vat' => ['country' => 'AE', 'percent' => (float) config('engage.stripe.uae_vat_percent', 5), 'inclusive' => false],
                'plans' => $plans,
            ];
        });

        // The website calls this from its server, so no cross-origin browser access is needed.
        return response()->json(['data' => $data])->header('Cache-Control', 'public, max-age=60');
    }

    /** Demo / contact form on the website. Stored, then emailed to the sales inbox. */
    public function lead(Request $request): JsonResponse
    {
        $data = $request->validate([
            // A trial sign-up starts with only an email address; every other form asks for a name.
            'name' => ['required_unless:topic,trial', 'nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'team_size' => ['nullable', 'string', 'max:40'],
            'topic' => ['nullable', 'in:demo,contact,enterprise,trial'],
            'message' => ['nullable', 'string', 'max:4000'],
            'source' => ['nullable', 'string', 'max:190'],
            // How long the form was open before it was sent, measured by the page itself.
            'elapsed_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        // Bot check. There is NO hidden field any more: browsers' autofill and password managers kept
        // filling hidden fields in for real visitors (first one named "website", then "confirm_code"),
        // and real inquiries were marked as bots. The only signal used now is one a person cannot
        // trigger: the form being sent within a second and a half of the page opening. An inquiry
        // without the measurement (an older cached page) is always treated as real.
        // Either way nothing is discarded: a suspected bot is kept as "spam" and just not emailed.
        $trapped = isset($data['elapsed_ms']) && (int) $data['elapsed_ms'] < 1500;
        unset($data['elapsed_ms']);

        $lead = SalesLead::query()->create(array_merge($data, [
            'name' => $data['name'] ?? $data['email'],
            'topic' => $data['topic'] ?? 'demo',
            'status' => $trapped ? 'spam' : 'new',
            'ip_address' => $request->ip(),
        ]));

        $to = (string) config('engage.sales_email');
        if (! $trapped && $to !== '') {
            Notification::route('mail', $to)->notify(new SalesLeadNotification($lead));
        }

        return response()->json(['data' => ['status' => 'received']], 201);
    }
}
