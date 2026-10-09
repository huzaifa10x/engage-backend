<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Application\Billing\StripeBilling;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Infrastructure\Stripe\StripeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Plan, billing details (company name, country, VAT TRN), invoices and Stripe-hosted flows. */
final class BillingController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StripeBilling $billing,
        private readonly StripeClient $stripe,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->tenant();
        $subscription = Subscription::query()->live()->with('planVersion.plan')->first();
        $currentKey = $subscription->planVersion->plan->key ?? (string) config('engage.plans.fallback');
        $vat = StripeBilling::vatApplies($tenant);
        $percent = (float) config('engage.stripe.uae_vat_percent', 5);

        $plans = Plan::query()->where('is_public', true)->where(fn ($q) => $q->where('is_active', true)->orWhere('key', $currentKey))->orderBy('sort_order')->get()->map(function (Plan $plan) use ($currentKey) {
            $version = $plan->activeVersion();

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
            ];
        })->filter()->values();

        return response()->json(['data' => [
            'stripe_configured' => $this->stripe->configured() && (string) config('engage.stripe.key') !== '',
            // Publishable key: safe to expose; the card form in the browser needs it.
            'stripe_publishable_key' => config('engage.stripe.key'),
            'plan' => ['key' => $currentKey, 'name' => $subscription->planVersion->plan->name ?? 'Free'],
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status->value,
                'provider' => $subscription->provider->value,
                'interval' => $subscription->billing_interval,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at' => $subscription->cancel_at?->toIso8601String(),
            ],
            'details' => [
                'legal_name' => $tenant->legal_name,
                'country' => $tenant->country,
                'tax_trn' => $tenant->tax_trn,
                'billing_email' => $tenant->billing_email,
            ],
            'vat' => ['applies' => $vat, 'percent' => $percent, 'country' => StripeBilling::VAT_COUNTRY],
            'has_payment_history' => $tenant->stripe_customer_id !== null,
            'plans' => $plans,
        ]]);
    }

    /** Company name, country and VAT TRN as they should appear on invoices. */
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

    /** The billing summary for a plan the customer is about to choose (shown before they confirm). */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:32'],
            'interval' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);
        if ($this->context->tenant()->country === null) {
            throw ValidationException::withMessages(['country' => 'Choose your billing country first, so the correct tax is applied.']);
        }

        return response()->json(['data' => $this->billing->preview($this->context->tenant(), $data['plan'], $data['interval'], $this->context->membership())]);
    }

    /** Card payments (succeeded, failed, refunded), the next renewal charge, and the wallet (credit kept from downgrades). */
    public function payments(): JsonResponse
    {
        $configured = $this->stripe->configured();

        return response()->json(['data' => [
            'payments' => $configured ? $this->billing->payments($this->context->tenant()) : [],
            'upcoming' => $configured ? $this->billing->upcoming($this->context->tenant()) : null,
            'wallet' => $configured ? $this->billing->wallet($this->context->tenant()) : ['balance_minor' => 0, 'currency' => 'USD', 'entries' => []],
        ]]);
    }

    /**
     * Buy or switch plan with a saved card. Returns `requires_confirmation` + a client secret when
     * the browser must confirm the payment with Stripe (card check / 3-D Secure) inside the page.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:32'],
            'interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'payment_method' => ['nullable', 'string', 'max:64', 'starts_with:pm_'],
        ]);

        return response()->json(['data' => $this->billing->subscribe($this->context->tenant(), $data['plan'], $data['interval'], $this->context->membership(), $data['payment_method'] ?? null)]);
    }

    /** Called after the in-page payment step: read the result from Stripe now (no waiting for webhooks). */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['abandon' => ['nullable', 'boolean']]);
        $status = $this->billing->refresh($this->context->tenant(), (bool) ($data['abandon'] ?? false));

        return response()->json(['data' => ['status' => $status]]);
    }

    public function paymentMethods(): JsonResponse
    {
        return response()->json(['data' => $this->stripe->configured() ? $this->billing->paymentMethods($this->context->tenant()) : []]);
    }

    /** Start adding a card: the browser completes it with Stripe using this client secret. */
    public function setupIntent(): JsonResponse
    {
        $email = $this->context->membership()?->user()->value('email');

        return response()->json(['data' => ['client_secret' => $this->billing->setupIntent($this->context->tenant(), is_string($email) ? $email : null)]]);
    }

    public function setDefaultPaymentMethod(string $paymentMethod): JsonResponse
    {
        $this->billing->setDefaultPaymentMethod($this->context->tenant(), $paymentMethod);

        return $this->paymentMethods();
    }

    public function removePaymentMethod(string $paymentMethod): JsonResponse
    {
        $this->billing->removePaymentMethod($this->context->tenant(), $paymentMethod);

        return $this->paymentMethods();
    }

    public function payInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['nullable', 'string', 'max:64', 'starts_with:pm_']]);

        return response()->json(['data' => $this->billing->payInvoice($this->context->tenant(), $invoice, $data['payment_method'] ?? null)]);
    }

    /** After an in-page invoice payment was confirmed by the browser: mirror the invoice now. */
    public function refreshInvoice(Invoice $invoice): JsonResponse
    {
        $this->billing->syncInvoice($invoice->stripe_invoice_id);

        return $this->invoices();
    }

    public function cancel(): JsonResponse
    {
        $this->billing->setCancelAtPeriodEnd($this->context->tenant(), true);

        return $this->show();
    }

    public function resume(): JsonResponse
    {
        $this->billing->setCancelAtPeriodEnd($this->context->tenant(), false);

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
            'invoice_pdf' => $i->invoice_pdf,
        ])]);
    }
}
