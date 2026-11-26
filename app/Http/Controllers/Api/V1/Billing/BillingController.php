<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Application\Billing\StripeBilling;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Enums\BillingProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Billing page (Batch 6): plan, billing details, invoices, and the Stripe-hosted flows. Every money
 * action opens a Stripe page (Checkout or the Customer Portal); the plan changes when Stripe's
 * webhook confirms it. No card is entered on, or stored by, engageX.
 */
final class BillingController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StripeBilling $billing,
        private readonly StripeClient $stripe,
    ) {}

    /** Also served as GET billing/summary, which the return page polls while Stripe confirms. */
    public function show(): JsonResponse
    {
        $tenant = $this->context->tenant();
        $subscription = Subscription::query()->live()->with(['planVersion.plan', 'scheduledPlanVersion.plan'])->first();
        $scheduled = $subscription?->scheduled_change_at !== null && $subscription->scheduled_change_at->isFuture() ? $subscription->scheduledPlanVersion : null;
        $currentKey = $subscription->planVersion->plan->key ?? (string) config('engage.plans.fallback');
        $vat = StripeBilling::vatApplies($tenant);
        $percent = (float) config('engage.stripe.uae_vat_percent', 5);

        $fallback = (string) config('engage.plans.fallback');
        $plans = Plan::query()->where(fn ($q) => $q->where('is_public', true)->orWhere('key', $currentKey))
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('key', $currentKey))->orderBy('sort_order')->get()->map(function (Plan $plan) use ($currentKey, $fallback) {
                $version = $plan->activeVersion();
                // A $0 plan other than Free (e.g. In-House) is assigned by the platform team only: never
                // offered to other workspaces; the workspace on it sees it as its plan.
                if ($version !== null && $plan->key !== $fallback && $plan->key !== $currentKey && $version->price_monthly_minor === 0) {
                    return null;
                }

                return $version === null ? null : [
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'currency' => $version->currency,
                    'price_monthly_minor' => $version->price_monthly_minor,
                    'price_yearly_minor' => $version->price_yearly_minor,
                    'current' => $plan->key === $currentKey,
                    // Free has no price to pay; custom-priced plans (null) go through sales.
                    'purchasable' => $plan->is_active && ($version->price_monthly_minor ?? 0) > 0,
                    // The plan a workspace falls back to.
                    'free' => $plan->key === $fallback,
                ];
            })->filter()->values();

        $selfServe = $subscription !== null && $subscription->provider === BillingProvider::Stripe && $subscription->provider_subscription_id !== null
            && $this->knownToStripe($tenant);

        return response()->json(['data' => [
            'stripe_configured' => $this->stripe->configured(),
            'plan' => ['key' => $currentKey, 'name' => $subscription->planVersion->plan->name ?? 'Free'],
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status->value,
                'provider' => $subscription->provider->value,
                'interval' => $subscription->billing_interval,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at' => $subscription->cancel_at?->toIso8601String(),
                // Paid by card through Stripe: changed in the Customer Portal. Otherwise set up by 10X Digital.
                'self_serve' => $selfServe,
                // No grace period: while past due the paid features are paused (messaging keeps working).
                'paused' => $subscription->status === SubscriptionStatus::PastDue,
                'scheduled_change' => $scheduled === null ? null : [
                    'plan' => ['key' => $scheduled->plan->key, 'name' => $scheduled->plan->name],
                    'interval' => $subscription->scheduled_interval,
                    'at' => $subscription->scheduled_change_at->toIso8601String(),
                ],
            ],
            'details' => [
                'legal_name' => $tenant->legal_name,
                'country' => $tenant->country,
                'tax_trn' => $tenant->tax_trn,
                'billing_email' => $tenant->billing_email,
            ],
            // automatic: Stripe Tax works VAT out at Checkout; otherwise the billing country must be set first.
            'vat' => ['applies' => $vat, 'percent' => $percent, 'country' => StripeBilling::VAT_COUNTRY, 'automatic' => (bool) config('engage.stripe.automatic_tax')],
            'has_payment_history' => $tenant->stripe_customer_id !== null,
            // A trial can move to Free at once; a paid plan is cancelled in the portal (flow "cancel").
            'can_switch_to_free' => $subscription !== null && $currentKey !== $fallback && $subscription->status === SubscriptionStatus::Trialing,
            'plans' => $plans,
        ]]);
    }

    /** Company name, country, VAT TRN and billing email as they should appear on invoices. */
    public function updateDetails(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:190'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'tax_trn' => ['nullable', 'string', 'max:32'],
            'billing_email' => ['nullable', 'email:rfc', 'max:190'],
        ]);
        $data['country'] = strtoupper($data['country']);
        $trn = preg_replace('/[\s-]+/', '', (string) ($data['tax_trn'] ?? ''));
        if ($trn !== '' && $data['country'] === StripeBilling::VAT_COUNTRY && preg_match('/^\d{15}$/', $trn) !== 1) {
            throw ValidationException::withMessages(['tax_trn' => 'A UAE TRN has exactly 15 digits.']);
        }
        $data['tax_trn'] = $trn !== '' ? $trn : null;

        $tenant = $this->context->tenant();
        $before = $tenant->only(array_keys($data));
        $tenant->fill($data)->save();
        $audit->record('billing.details_updated', $tenant, before: $before, after: $data);

        // Keep Stripe in step, so the next invoice carries the new details and the right tax.
        if ($tenant->stripe_customer_id !== null && $this->stripe->configured()) {
            $this->billing->syncCustomer($tenant);
            $this->billing->applyTaxToSubscription($tenant);
        }

        return $this->show();
    }

    /** Card payments (succeeded, failed, refunded) and the next renewal charge. No credit is kept: there is no wallet. */
    public function payments(): JsonResponse
    {
        $configured = $this->stripe->configured();

        return response()->json(['data' => [
            'payments' => $configured ? $this->billing->payments($this->context->tenant()) : [],
            'upcoming' => $configured ? $this->billing->upcoming($this->context->tenant()) : null,
        ]]);
    }

    /** The card renewals are charged to, read from Stripe (brand, last 4, expiry). Never stored. */
    public function paymentMethod(): JsonResponse
    {
        return response()->json(['data' => $this->stripe->configured() ? $this->billing->paymentMethod($this->context->tenant()) : null]);
    }

    /** Upgrade from Free (or a trial): a Stripe Checkout page. The browser goes to the returned URL. */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:32'],
            'interval' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);
        $this->ensureConfigured();

        return response()->json(['data' => ['url' => $this->billing->checkout($this->context->tenant(), $data['plan'], $data['interval'], $this->context->membership())]]);
    }

    /**
     * A Stripe Customer Portal page for one task: change plan (update), card / pay now (card),
     * cancel at period end (cancel), or the portal home to undo a scheduled change (manage).
     */
    public function portal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flow' => ['required', Rule::in(['update', 'card', 'cancel', 'manage'])],
            'plan' => ['required_if:flow,update', 'nullable', 'string', 'max:32'],
            'interval' => ['required_if:flow,update', 'nullable', Rule::in(['monthly', 'yearly'])],
        ]);
        $this->ensureConfigured();

        return response()->json(['data' => ['url' => $this->billing->portal($this->context->tenant(), $data['flow'], $data['plan'] ?? null, $data['interval'] ?? null)]]);
    }

    /**
     * The client is back from Stripe. A Checkout return is checked against Stripe (this workspace,
     * complete, paid) but grants nothing: the page then polls the summary until the webhook has
     * changed the plan. Back from the card screen, any open invoice is retried with the new card.
     */
    public function confirmReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255', 'regex:/^cs_(test|live)_[A-Za-z0-9]+$/'],
            'flow' => ['nullable', Rule::in(['update', 'card', 'cancel', 'manage'])],
        ]);
        $this->ensureConfigured();
        $tenant = $this->context->tenant();

        if (isset($data['session_id'])) {
            return response()->json(['data' => ['status' => $this->billing->confirmCheckout($tenant, $data['session_id']), 'message' => null]]);
        }
        if (($data['flow'] ?? null) === 'card') {
            return response()->json(['data' => $this->billing->afterCardUpdate($tenant)]);
        }
        $this->billing->resync($tenant);

        return response()->json(['data' => ['status' => 'synced', 'message' => null]]);
    }

    /**
     * "Switch to Free" for a trial: straight away. A paid Stripe plan is cancelled in the Customer
     * Portal instead (it ends with the paid period). Plans set up by 10X Digital are changed by them.
     */
    public function switchToFree(SubscriptionService $subscriptions): JsonResponse
    {
        $tenant = $this->context->tenant();
        $fallback = (string) config('engage.plans.fallback');
        $live = Subscription::query()->live()->with('planVersion.plan')->first();

        if ($live === null || ($live->planVersion->plan->key ?? null) === $fallback) {
            return $this->show();
        }
        if ($this->billing->liveStripeSubscription($tenant) !== null) {
            throw ValidationException::withMessages(['billing' => 'Cancel your paid plan with "Cancel plan": Stripe confirms it, and the workspace moves to Free at the end of the paid period.']);
        }
        if ($live->status !== SubscriptionStatus::Trialing) {
            throw ValidationException::withMessages(['billing' => 'Your plan is managed by 10X Digital. Contact us to change it.']);
        }

        $subscriptions->assign($tenant, Plan::byKey($fallback)->activeVersionOrFail(), SubscriptionStatus::Active, null, 'switched_to_free');

        return $this->show();
    }

    public function invoices(): JsonResponse
    {
        return response()->json(['data' => Invoice::query()->where('status', '!=', 'draft')->orderByDesc('issued_at')->limit(100)->get()->map(fn (Invoice $i) => [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->paymentStatus(),
            'currency' => $i->currency,
            'subtotal_minor' => $i->subtotal_minor,
            'tax_minor' => $i->tax_minor,
            'total_minor' => $i->total_minor,
            'amount_refunded_minor' => $i->amount_refunded_minor,
            'description' => $i->description,
            'issued_at' => $i->issued_at?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'hosted_invoice_url' => $i->hosted_invoice_url,
            // Download through billing/invoices/{id}/pdf: Stripe's PDF links are fetched fresh each time.
            'has_pdf' => $i->invoice_pdf !== null,
        ])]);
    }

    /** Stripe's PDF of an invoice: a fresh link from Stripe each time (never kept long-term). */
    public function invoicePdf(Invoice $invoice): JsonResponse
    {
        $this->ensureConfigured();
        $url = $this->billing->invoicePdf($invoice);
        if ($url === null) {
            throw ValidationException::withMessages(['invoice' => 'Stripe has no PDF for this invoice yet.']);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * Whether the workspace's Stripe subscription exists with the keys in use. One made with other
     * keys (test ↔ live, another Stripe account) cannot be managed in the portal: the page offers
     * Checkout instead, and the new subscription replaces it. If Stripe cannot be reached, the
     * stored state is trusted so the page still loads.
     */
    private function knownToStripe(Tenant $tenant): bool
    {
        if (! $this->stripe->configured()) {
            return false;
        }
        try {
            return $this->billing->liveStripeSubscription($tenant) !== null;
        } catch (StripeException) {
            return true;
        }
    }

    private function ensureConfigured(): void
    {
        if (! $this->stripe->configured()) {
            throw StripeException::notConfigured();
        }
    }
}
