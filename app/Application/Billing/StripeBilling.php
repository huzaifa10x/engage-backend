<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Enums\BillingProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Everything money-related goes through Stripe: customers, subscriptions (Checkout), invoices,
 * payment methods (Customer Portal), VAT and refunds. This class starts those flows and mirrors
 * Stripe's state back into subscriptions / invoices when Stripe's webhooks say something changed.
 *
 * VAT rule: a workspace whose country is the UAE is charged VAT (5%, exclusive) on top of the
 * USD plan price; everyone else is not. The customer's company name and TRN are stored on the
 * Stripe customer so they are printed on every invoice.
 */
final class StripeBilling
{
    public const VAT_COUNTRY = 'AE';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly SubscriptionService $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public static function vatApplies(Tenant $tenant): bool
    {
        return strtoupper((string) $tenant->country) === self::VAT_COUNTRY;
    }

    // ── Customer ───────────────────────────────────────────────────────────────────────────

    /** Creates or updates the Stripe customer (name, email, country, TRN) for a workspace. */
    public function syncCustomer(Tenant $tenant, ?string $fallbackEmail = null): string
    {
        $params = array_filter([
            'name' => $tenant->legal_name ?: $tenant->name,
            'email' => $tenant->billing_email ?: $fallbackEmail,
            'phone' => $tenant->phone,
            'address' => $tenant->country !== null ? array_filter([
                'country' => strtoupper($tenant->country),
                'line1' => $tenant->address_line1,
                'line2' => $tenant->address_line2,
                'city' => $tenant->city,
                'state' => $tenant->region,
                'postal_code' => $tenant->postal_code,
            ]) : null,
            'metadata' => ['tenant_id' => $tenant->id, 'workspace' => $tenant->name],
        ]);

        if ($tenant->stripe_customer_id !== null) {
            try {
                $this->stripe->post("customers/{$tenant->stripe_customer_id}", $params);
            } catch (StripeException $e) {
                if ($e->stripeCode !== 'resource_missing') {
                    throw $e;
                }
                // The stored customer belongs to the other Stripe mode (test ↔ live) or was deleted:
                // forget it and create a fresh one below.
                $this->context->bypass(fn () => $tenant->forceFill(['stripe_customer_id' => null])->save());
            }
        }
        if ($tenant->stripe_customer_id === null) {
            $customer = $this->stripe->post('customers', $params);
            $this->context->bypass(fn () => $tenant->forceFill(['stripe_customer_id' => (string) $customer['id']])->save());
        }
        $customerId = (string) $tenant->stripe_customer_id;

        // TRN on the invoice: exactly one ae_trn tax ID on the customer, or none.
        $wanted = self::vatApplies($tenant) && $tenant->tax_trn !== null ? $tenant->tax_trn : null;
        $present = false;
        foreach ((array) ($this->stripe->get("customers/{$customerId}/tax_ids", ['limit' => 20])['data'] ?? []) as $taxId) {
            if (($taxId['type'] ?? null) === 'ae_trn' && ($taxId['value'] ?? null) === $wanted) {
                $present = true;
            } elseif (($taxId['type'] ?? null) === 'ae_trn') {
                $this->stripe->delete("customers/{$customerId}/tax_ids/{$taxId['id']}");
            }
        }
        if ($wanted !== null && ! $present) {
            try {
                $this->stripe->post("customers/{$customerId}/tax_ids", ['type' => 'ae_trn', 'value' => $wanted]);
            } catch (StripeException $e) {
                throw ValidationException::withMessages(['tax_trn' => 'Stripe did not accept this TRN: '.$e->getMessage()]);
            }
        }

        return $customerId;
    }

    // ── Payment methods (cards are entered in Stripe's secure fields inside our page) ───────

    /** A SetupIntent client secret: the browser uses it to save a card without it touching our servers. */
    public function setupIntent(Tenant $tenant, ?string $fallbackEmail = null): string
    {
        $customer = $this->syncCustomer($tenant, $fallbackEmail); // also heals a customer left over from the other Stripe mode

        return (string) $this->stripe->post('setup_intents', ['customer' => $customer, 'usage' => 'off_session', 'payment_method_types' => ['card']])['client_secret'];
    }

    /** @return list<array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, is_default: bool}> */
    public function paymentMethods(Tenant $tenant): array
    {
        if ($tenant->stripe_customer_id === null) {
            return [];
        }
        try {
            $customer = $this->stripe->get("customers/{$tenant->stripe_customer_id}");
        } catch (StripeException $e) {
            if ($e->stripeCode === 'resource_missing') {
                return []; // customer from the other Stripe mode: recreated on the next billing action
            }
            throw $e;
        }
        $default = $customer['invoice_settings']['default_payment_method'] ?? null;
        $methods = (array) ($this->stripe->get("customers/{$tenant->stripe_customer_id}/payment_methods", ['type' => 'card', 'limit' => 20])['data'] ?? []);

        // A first card becomes the default by itself, so the customer never has "a card but no default".
        if ($default === null && $methods !== []) {
            $default = (string) $methods[0]['id'];
            $this->stripe->post("customers/{$tenant->stripe_customer_id}", ['invoice_settings' => ['default_payment_method' => $default]]);
        }

        return array_map(fn (array $m) => [
            'id' => (string) $m['id'],
            'brand' => (string) ($m['card']['brand'] ?? 'card'),
            'last4' => (string) ($m['card']['last4'] ?? ''),
            'exp_month' => (int) ($m['card']['exp_month'] ?? 0),
            'exp_year' => (int) ($m['card']['exp_year'] ?? 0),
            'is_default' => $m['id'] === $default,
        ], array_values($methods));
    }

    public function setDefaultPaymentMethod(Tenant $tenant, string $paymentMethodId): void
    {
        $this->assertOwnsPaymentMethod($tenant, $paymentMethodId);
        $this->stripe->post("customers/{$tenant->stripe_customer_id}", ['invoice_settings' => ['default_payment_method' => $paymentMethodId]]);

        $live = $this->liveStripeSubscription($tenant);
        if ($live !== null) {
            $this->stripe->post("subscriptions/{$live->provider_subscription_id}", ['default_payment_method' => $paymentMethodId]);
        }
        $this->audit->record('billing.default_payment_method_changed', $tenant);
    }

    public function removePaymentMethod(Tenant $tenant, string $paymentMethodId): void
    {
        $this->assertOwnsPaymentMethod($tenant, $paymentMethodId);

        $methods = $this->paymentMethods($tenant);
        $live = $this->liveStripeSubscription($tenant);
        if (count($methods) === 1 && $live !== null && $live->cancel_at === null) {
            throw ValidationException::withMessages(['payment_method' => 'This is the only card on an active subscription. Add another card first, or cancel the plan.']);
        }

        $this->stripe->post("payment_methods/{$paymentMethodId}/detach");
        $this->audit->record('billing.payment_method_removed', $tenant);

        // Removing the default: promote another card so renewals keep working.
        $removed = array_values(array_filter($methods, fn (array $m) => $m['id'] === $paymentMethodId))[0] ?? null;
        $next = array_values(array_filter($methods, fn (array $m) => $m['id'] !== $paymentMethodId))[0] ?? null;
        if ($removed !== null && $removed['is_default'] && $next !== null) {
            $this->stripe->post("customers/{$tenant->stripe_customer_id}", ['invoice_settings' => ['default_payment_method' => $next['id']]]);
        }
    }

    private function assertOwnsPaymentMethod(Tenant $tenant, string $paymentMethodId): void
    {
        $owner = $tenant->stripe_customer_id !== null ? ($this->stripe->get("payment_methods/{$paymentMethodId}")['customer'] ?? null) : null;
        if ($owner === null || $owner !== $tenant->stripe_customer_id) {
            throw ValidationException::withMessages(['payment_method' => 'This card does not belong to your workspace.']);
        }
    }

    // ── Starting / changing a subscription (in-app, always confirmed first) ────────────────

    /**
     * The billing summary shown before the customer confirms: exactly what Stripe will invoice.
     *
     * New subscription → the plan price (+ VAT). Plan or interval change → Stripe's own proration:
     * the unused part of the current period comes back as a credit, the new plan is charged in
     * full, and a new billing period starts today. Nothing here is calculated by us.
     *
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, string $planKey, string $interval, ?TenantMembership $actor = null): array
    {
        [$version, $plan] = $this->purchasable($planKey, $interval);
        $customer = $this->syncCustomer($tenant, $actor?->user()->value('email'));
        $live = $this->liveStripeSubscription($tenant);

        $invoice = $this->withCatalog($version, function () use ($tenant, $version, $interval, $customer, $live): array {
            $price = $this->priceId($version, $interval);

            if ($live === null) {
                return $this->stripe->get('invoices/upcoming', array_filter([
                    'customer' => $customer,
                    'subscription_items' => [['price' => $price, 'quantity' => 1]],
                    'subscription_default_tax_rates' => self::vatApplies($tenant) ? [$this->uaeTaxRateId()] : null,
                ]));
            }

            $remote = $this->stripe->get("subscriptions/{$live->provider_subscription_id}");

            return $this->stripe->get('invoices/upcoming', [
                'customer' => $customer,
                'subscription' => $live->provider_subscription_id,
                'subscription_items' => [['id' => $remote['items']['data'][0]['id'] ?? null, 'price' => $price]],
                'subscription_proration_behavior' => 'always_invoice',
                'subscription_billing_cycle_anchor' => 'now',
            ]);
        });

        $lines = [];
        $credit = 0;
        foreach ((array) ($invoice['lines']['data'] ?? []) as $line) {
            $amount = (int) ($line['amount'] ?? 0);
            $credit += $amount < 0 ? -$amount : 0;
            $lines[] = ['description' => (string) ($line['description'] ?? ''), 'amount_minor' => $amount, 'proration' => (bool) ($line['proration'] ?? false)];
        }
        $total = (int) ($invoice['total'] ?? 0);
        $due = (int) ($invoice['amount_due'] ?? max(0, $total));
        $current = $live?->planVersion()->with('plan')->first();
        $amount = (int) ($interval === 'yearly' ? $version->price_yearly_minor : $version->price_monthly_minor);

        return [
            'plan' => ['key' => $plan->key, 'name' => $plan->name],
            'interval' => $interval,
            'price_minor' => $amount,
            'currency' => strtoupper((string) ($invoice['currency'] ?? $version->currency)),
            'change' => $live !== null,
            'from' => $live === null ? null : ['plan' => $current?->plan?->name, 'interval' => $live->billing_interval],
            'lines' => $lines,
            'unused_credit_minor' => $credit,
            'subtotal_minor' => (int) ($invoice['subtotal'] ?? 0),
            'tax_minor' => (int) ($invoice['tax'] ?? 0),
            'tax_percent' => self::vatApplies($tenant) ? (float) config('engage.stripe.uae_vat_percent', 5) : 0,
            'total_minor' => $total,
            // Money already on the account (earlier credits) that Stripe applies to this invoice.
            'balance_applied_minor' => max(0, (int) ($invoice['starting_balance'] ?? 0) * -1 - max(0, (int) ($invoice['ending_balance'] ?? 0) * -1)),
            'amount_due_minor' => max(0, $due),
            // A change that costs less than the unused credit: the rest stays on the account for future invoices.
            'credit_kept_minor' => $total < 0 ? -$total : 0,
            'renews_at' => ($interval === 'yearly' ? now()->addYear() : now()->addMonth())->toIso8601String(),
            'has_payment_method' => $this->paymentMethods($tenant) !== [],
        ];
    }

    /**
     * Carry out what preview() showed, after the customer confirmed.
     *
     * New subscription → first invoice + PaymentIntent, confirmed by the browser. Change → the
     * subscription is updated with `pending_if_incomplete`: Stripe invoices the prorated amount
     * NOW and only applies the new plan once that payment has succeeded.
     *
     * @return array{status: string, updated: bool, client_secret: ?string, payment_method: ?string}
     */
    public function subscribe(Tenant $tenant, string $planKey, string $interval, ?TenantMembership $actor = null, ?string $paymentMethodId = null): array
    {
        [$version] = $this->purchasable($planKey, $interval);
        if ($tenant->country === null) {
            throw ValidationException::withMessages(['country' => 'Choose your billing country first, so the correct tax is applied.']);
        }

        $customer = $this->syncCustomer($tenant, $actor?->user()->value('email'));
        $methods = $this->paymentMethods($tenant);
        $method = $paymentMethodId ?? (array_values(array_filter($methods, fn (array $m) => $m['is_default']))[0]['id'] ?? null);
        if ($method === null || ! in_array($method, array_column($methods, 'id'), true)) {
            throw ValidationException::withMessages(['payment_method' => 'Add a card first, then choose your plan.']);
        }

        $live = $this->liveStripeSubscription($tenant);

        $subscription = $this->withCatalog($version, function () use ($tenant, $version, $interval, $customer, $method, $live): array {
            $price = $this->priceId($version, $interval);
            $taxRates = self::vatApplies($tenant) ? [$this->uaeTaxRateId()] : [];

            if ($live === null) {
                return $this->stripe->post('subscriptions', [
                    'customer' => $customer,
                    'items' => [['price' => $price]],
                    'default_payment_method' => $method,
                    'default_tax_rates' => $taxRates ?: null,
                    'metadata' => ['tenant_id' => $tenant->id, 'plan_version_id' => $version->id],
                    'payment_behavior' => 'default_incomplete',
                    'payment_settings' => ['save_default_payment_method' => 'on_subscription', 'payment_method_types' => ['card']],
                    'expand' => ['latest_invoice.payment_intent'],
                ]);
            }

            // Settings that may not travel with a pending update go first (they change no money).
            $remote = $this->stripe->post("subscriptions/{$live->provider_subscription_id}", [
                'cancel_at_period_end' => 'false',
                'collection_method' => 'charge_automatically',
                'default_payment_method' => $method,
                'default_tax_rates' => $taxRates === [] ? '' : $taxRates,
            ]);

            return $this->stripe->post("subscriptions/{$live->provider_subscription_id}", [
                'items' => [['id' => $remote['items']['data'][0]['id'] ?? null, 'price' => $price]],
                'proration_behavior' => 'always_invoice',   // charge the difference today …
                'billing_cycle_anchor' => 'now',            // … and start a new period on the new plan
                'payment_behavior' => 'pending_if_incomplete', // the plan changes only after the payment succeeds
                'expand' => ['latest_invoice.payment_intent'],
            ]);
        });

        $this->audit->record($live === null ? 'billing.subscription_started' : 'billing.plan_change_requested', $tenant, meta: ['plan' => $planKey, 'interval' => $interval]);

        $intent = is_array($subscription['latest_invoice']['payment_intent'] ?? null) ? $subscription['latest_invoice']['payment_intent'] : null;
        $pending = $live === null ? ($subscription['status'] ?? null) !== 'active' : ! empty($subscription['pending_update']);

        if (! $pending || $intent === null || ($intent['status'] ?? null) === 'succeeded') {
            $this->syncSubscription($this->stripe->get('subscriptions/'.$subscription['id']));

            return ['status' => 'active', 'updated' => $live !== null, 'client_secret' => null, 'payment_method' => null];
        }

        if ($live !== null && ($intent['status'] ?? null) === 'requires_payment_method') {
            // The card was declined outright: withdraw the invoice, the current plan stays as it is.
            $this->voidInvoice($subscription['latest_invoice']['id'] ?? null);
            $reason = (string) ($intent['last_payment_error']['message'] ?? 'Your card was declined.');

            throw ValidationException::withMessages(['payment_method' => "{$reason} Your plan was not changed."]);
        }

        // The browser now confirms the payment with Stripe (card check / 3-D Secure), in-page.
        return ['status' => 'requires_confirmation', 'updated' => $live !== null, 'client_secret' => (string) $intent['client_secret'], 'payment_method' => $method];
    }

    /**
     * After the browser finished (or gave up on) a payment: read the truth from Stripe now rather
     * than waiting for the webhook. An unpaid attempt is withdrawn so it cannot linger.
     *
     * @return 'active'|'pending'|'failed'
     */
    public function refresh(Tenant $tenant, bool $abandon = false): string
    {
        if ($tenant->stripe_customer_id === null) {
            return 'failed';
        }
        $subscriptions = (array) ($this->stripe->get('subscriptions', ['customer' => $tenant->stripe_customer_id, 'status' => 'all', 'limit' => 5])['data'] ?? []);

        foreach ($subscriptions as $remote) {
            if (in_array($remote['status'] ?? '', ['active', 'trialing', 'past_due'], true)) {
                if (! empty($remote['pending_update'])) {
                    // A plan change is still waiting for its payment.
                    if ($abandon) {
                        $this->voidInvoice(is_string($remote['latest_invoice'] ?? null) ? $remote['latest_invoice'] : null);
                        $this->syncSubscription($this->stripe->get('subscriptions/'.$remote['id']));

                        return 'failed';
                    }

                    return 'pending';
                }
                $this->syncSubscription($remote);

                return 'active';
            }
        }
        foreach ($subscriptions as $remote) {
            if (($remote['status'] ?? '') === 'incomplete') {
                if ($abandon) {
                    $this->stripe->delete('subscriptions/'.$remote['id']);

                    return 'failed';
                }

                return 'pending';
            }
        }

        return 'failed';
    }

    /**
     * Every card payment of this workspace, newest first (straight from Stripe).
     *
     * @return list<array<string, mixed>>
     */
    public function payments(Tenant $tenant): array
    {
        if ($tenant->stripe_customer_id === null) {
            return [];
        }
        try {
            $charges = (array) ($this->stripe->get('charges', ['customer' => $tenant->stripe_customer_id, 'limit' => 50])['data'] ?? []);
        } catch (StripeException $e) {
            if ($e->stripeCode === 'resource_missing') {
                return [];
            }
            throw $e;
        }

        return array_map(fn (array $c) => [
            'id' => (string) $c['id'],
            'amount_minor' => (int) ($c['amount'] ?? 0),
            'amount_refunded_minor' => (int) ($c['amount_refunded'] ?? 0),
            'currency' => strtoupper((string) ($c['currency'] ?? 'usd')),
            'status' => ($c['refunded'] ?? false) ? 'refunded' : (((int) ($c['amount_refunded'] ?? 0)) > 0 ? 'partially_refunded' : (string) ($c['status'] ?? 'pending')),
            'failure_message' => $c['failure_message'] ?? null,
            'card_brand' => $c['payment_method_details']['card']['brand'] ?? null,
            'card_last4' => $c['payment_method_details']['card']['last4'] ?? null,
            'description' => $c['description'] ?? null,
            'receipt_url' => $c['receipt_url'] ?? null,
            'created_at' => isset($c['created']) ? Carbon::createFromTimestamp((int) $c['created'])->toIso8601String() : null,
        ], array_values($charges));
    }

    /**
     * The next renewal charge of the running subscription, as Stripe will invoice it.
     *
     * @return ?array{amount_due_minor: int, currency: string, date: ?string}
     */
    public function upcoming(Tenant $tenant): ?array
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($live === null || $live->cancel_at !== null) {
            return null;
        }
        try {
            $invoice = $this->stripe->get('invoices/upcoming', ['customer' => $tenant->stripe_customer_id, 'subscription' => $live->provider_subscription_id]);
        } catch (StripeException) {
            return null; // nothing upcoming
        }
        $at = $invoice['next_payment_attempt'] ?? $invoice['period_end'] ?? null;

        return [
            'amount_due_minor' => (int) ($invoice['amount_due'] ?? 0),
            'currency' => strtoupper((string) ($invoice['currency'] ?? 'usd')),
            'date' => is_numeric($at) ? Carbon::createFromTimestamp((int) $at)->toIso8601String() : null,
        ];
    }

    /** @return array{0: PlanVersion, 1: Plan} */
    private function purchasable(string $planKey, string $interval): array
    {
        $plan = Plan::query()->where('key', $planKey)->where('is_public', true)->where('is_active', true)->first();
        $version = $plan?->activeVersion();
        $amount = $interval === 'yearly' ? $version?->price_yearly_minor : $version?->price_monthly_minor;
        if ($plan === null || $version === null || $amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['plan' => $interval === 'yearly' && $version?->price_monthly_minor ? 'This plan has no yearly price. Choose monthly billing.' : 'This plan cannot be bought online. Contact sales.']);
        }

        return [$version, $plan];
    }

    /**
     * Runs a Stripe call that uses catalog objects (price, tax rate). If one of them does not exist
     * in the current Stripe mode (test ↔ live switch), the stored IDs are dropped and it runs once
     * more, which re-creates them.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function withCatalog(PlanVersion $version, callable $call): mixed
    {
        try {
            return $call();
        } catch (StripeException $e) {
            if ($e->stripeCode !== 'resource_missing' || preg_match('/No such (price|tax rate|tax_rate)/i', $e->getMessage()) !== 1) {
                throw $e;
            }
            $version->forceFill(['stripe_price_monthly_id' => null, 'stripe_price_yearly_id' => null])->save();
            Cache::forget('stripe:uae_vat_tax_rate');

            return $call();
        }
    }

    private function voidInvoice(mixed $invoiceId): void
    {
        if (! is_string($invoiceId) || $invoiceId === '') {
            return;
        }
        try {
            $this->stripe->post("invoices/{$invoiceId}/void");
        } catch (StripeException) {
            // Already paid or void: nothing to withdraw.
        }
    }

    /**
     * Pay an open invoice with a saved card (after a failed renewal).
     *
     * @return array{status: string, client_secret: ?string, payment_method: ?string}
     */
    public function payInvoice(Tenant $tenant, Invoice $invoice, ?string $paymentMethodId = null): array
    {
        if ($invoice->status !== 'open') {
            throw ValidationException::withMessages(['invoice' => 'This invoice is not waiting for payment.']);
        }
        $methods = $this->paymentMethods($tenant);
        $method = $paymentMethodId ?? (array_values(array_filter($methods, fn (array $m) => $m['is_default']))[0]['id'] ?? null);
        if ($method === null || ! in_array($method, array_column($methods, 'id'), true)) {
            throw ValidationException::withMessages(['payment_method' => 'Add a card first.']);
        }

        try {
            $this->stripe->post("invoices/{$invoice->stripe_invoice_id}/pay", ['payment_method' => $method]);
        } catch (StripeException $e) {
            // The bank wants the customer to approve the payment: hand the PaymentIntent to the browser.
            $remote = $this->stripe->get("invoices/{$invoice->stripe_invoice_id}", ['expand' => ['payment_intent']]);
            $intent = is_array($remote['payment_intent'] ?? null) ? $remote['payment_intent'] : null;
            if ($intent !== null && $e->stripeCode === 'invoice_payment_intent_requires_action') {
                return ['status' => 'requires_confirmation', 'client_secret' => (string) $intent['client_secret'], 'payment_method' => $method];
            }

            throw ValidationException::withMessages(['payment_method' => 'The payment was declined: '.$e->getMessage()]);
        }

        $this->syncInvoice($invoice->stripe_invoice_id);
        $this->audit->record('billing.invoice_paid', $tenant, meta: ['invoice' => $invoice->number]);

        return ['status' => 'paid', 'client_secret' => null, 'payment_method' => null];
    }

    /** Refund a paid invoice in full or in part (Super Admin). Stripe returns the money to the card. */
    public function refundInvoice(Invoice $invoice, ?int $amountMinor = null, ?string $reason = null): void
    {
        $remote = $this->stripe->get("invoices/{$invoice->stripe_invoice_id}");
        $charge = $remote['charge'] ?? null;
        $refundable = $invoice->amount_paid_minor - $invoice->amount_refunded_minor;
        if (! is_string($charge) || $refundable <= 0) {
            throw ValidationException::withMessages(['amount' => 'There is nothing left to refund on this invoice.']);
        }
        if ($amountMinor !== null && ($amountMinor < 1 || $amountMinor > $refundable)) {
            throw ValidationException::withMessages(['amount' => 'The refund cannot be more than what was paid and not yet refunded.']);
        }

        try {
            $this->stripe->post('refunds', array_filter(['charge' => $charge, 'amount' => $amountMinor, 'reason' => 'requested_by_customer', 'metadata' => array_filter(['note' => $reason])]));
        } catch (StripeException $e) {
            throw ValidationException::withMessages(['amount' => 'Stripe could not refund this payment: '.$e->getMessage()]);
        }
        $this->syncInvoice($invoice->stripe_invoice_id);
    }

    /** Cancel at the end of the paid period (the workspace then moves to Free), or undo that. */
    public function setCancelAtPeriodEnd(Tenant $tenant, bool $cancel): void
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($live === null) {
            throw ValidationException::withMessages(['billing' => 'There is no paid subscription to change.']);
        }

        $this->syncSubscription($this->stripe->post("subscriptions/{$live->provider_subscription_id}", ['cancel_at_period_end' => $cancel ? 'true' : 'false']));
        $this->audit->record($cancel ? 'billing.cancel_scheduled' : 'billing.cancel_undone', $tenant);
    }

    /** After the billing country changed: add or remove VAT on the running subscription. */
    public function applyTaxToSubscription(Tenant $tenant): void
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($live !== null) {
            $this->stripe->post("subscriptions/{$live->provider_subscription_id}", [
                'default_tax_rates' => self::vatApplies($tenant) ? [$this->uaeTaxRateId()] : '',
            ]);
        }
    }

    // ── Mirroring Stripe (called from webhooks) ────────────────────────────────────────────

    /**
     * Brings the workspace's subscription in line with a Stripe subscription object.
     *
     * @param  array<string, mixed>  $remote
     */
    public function syncSubscription(array $remote): void
    {
        $tenant = $this->tenantFor($remote['metadata']['tenant_id'] ?? null, $remote['customer'] ?? null);
        if ($tenant === null) {
            return; // not one of ours (another product on the same Stripe account)
        }

        $stripeStatus = (string) ($remote['status'] ?? '');
        if (in_array($stripeStatus, ['canceled', 'incomplete_expired'], true)) {
            $this->endSubscription($tenant, (string) $remote['id']);

            return;
        }
        if ($stripeStatus === 'incomplete') {
            return; // first payment not confirmed yet
        }

        $priceId = (string) ($remote['items']['data'][0]['price']['id'] ?? '');
        $version = PlanVersion::query()->where('stripe_price_monthly_id', $priceId)->orWhere('stripe_price_yearly_id', $priceId)->orderByDesc('version')->first()
            ?? PlanVersion::query()->find($remote['metadata']['plan_version_id'] ?? null);
        if ($version === null) {
            return;
        }
        // A price can be shared by several versions of one plan (limits edited, price unchanged):
        // a workspace already moved to a newer version of that plan stays on it.
        $current = $this->context->bypass(fn () => Subscription::query()->where('tenant_id', $tenant->id)->live()->where('provider_subscription_id', (string) $remote['id'])->with('planVersion')->first());
        if ($current?->planVersion !== null && $current->planVersion->plan_id === $version->plan_id) {
            $version = $current->planVersion;
        }

        $status = match ($stripeStatus) {
            'trialing' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            default => SubscriptionStatus::PastDue, // past_due, unpaid, paused
        };
        $time = fn (mixed $ts): ?Carbon => is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : null;
        $cancelAt = ($remote['cancel_at_period_end'] ?? false) ? $time($remote['current_period_end'] ?? null) : $time($remote['cancel_at'] ?? null);

        $this->context->run($tenant, function () use ($tenant, $remote, $version, $status, $priceId, $time, $cancelAt): void {
            $live = Subscription::query()->live()->first();

            if ($live === null || $live->provider_subscription_id !== $remote['id']) {
                // A new Stripe subscription replaces whatever the workspace was on (trial, Free).
                $live = $this->subscriptions->assign($tenant, $version, $status, $time($remote['trial_end'] ?? null), 'stripe_subscription', BillingProvider::Stripe);
            } elseif ($live->plan_version_id !== $version->id) {
                // Same Stripe subscription, different plan (upgrade / downgrade in place).
                $from = $live->plan_version_id;
                $live->forceFill(['plan_version_id' => $version->id])->save();
                $this->subscriptions->transition($live, $status, 'stripe_plan_changed', ['from_plan_version' => $from, 'to_plan_version' => $version->id]);
                $this->audit->record('subscription.plan_changed', $live, before: ['plan_version_id' => $from], after: ['plan_version_id' => $version->id]);
            } elseif ($live->status !== $status) {
                $this->subscriptions->transition($live, $status, 'stripe_status_changed', ['stripe_status' => $remote['status']]);
            }

            $live->forceFill([
                'provider' => BillingProvider::Stripe,
                'provider_customer_id' => (string) $remote['customer'],
                'provider_subscription_id' => (string) $remote['id'],
                'billing_interval' => $priceId === $version->stripe_price_yearly_id ? 'yearly' : 'monthly',
                'current_period_start' => $time($remote['current_period_start'] ?? null),
                'current_period_end' => $time($remote['current_period_end'] ?? null),
                'cancel_at' => $cancelAt,
                'auto_pay' => ($remote['collection_method'] ?? 'charge_automatically') !== 'send_invoice',
            ])->save();
        });

        $this->entitlements->forget($tenant);
    }

    /** The Stripe subscription ended (cancelled, or unpaid for too long): move to the Free plan. */
    public function endSubscription(Tenant $tenant, string $stripeSubscriptionId): void
    {
        $ended = $this->context->run($tenant, function () use ($stripeSubscriptionId): bool {
            $live = Subscription::query()->live()->where('provider_subscription_id', $stripeSubscriptionId)->first();
            if ($live === null) {
                return false;
            }
            $this->subscriptions->transition($live, SubscriptionStatus::Canceled, 'stripe_subscription_ended');
            $live->forceFill(['canceled_at' => now(), 'ended_at' => now()])->save();

            return true;
        });

        if ($ended) {
            $free = Plan::byKey((string) config('engage.plans.fallback'))->activeVersionOrFail();
            $this->subscriptions->assign($tenant, $free, SubscriptionStatus::Active, null, 'downgraded_to_fallback');
        }
    }

    /** Re-reads one invoice from Stripe (with its charge, for refunds) and mirrors it. */
    public function syncInvoice(string $stripeInvoiceId): void
    {
        $remote = $this->stripe->get("invoices/{$stripeInvoiceId}", ['expand' => ['charge']]);
        $tenant = $this->tenantFor($remote['subscription_details']['metadata']['tenant_id'] ?? null, $remote['customer'] ?? null);
        if ($tenant === null) {
            return;
        }

        $time = fn (mixed $ts): ?Carbon => is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : null;
        $line = $remote['lines']['data'][0] ?? [];
        $charge = is_array($remote['charge'] ?? null) ? $remote['charge'] : [];

        $this->context->run($tenant, fn () => Invoice::query()->updateOrCreate(['stripe_invoice_id' => (string) $remote['id']], [
            'number' => $remote['number'] ?? null,
            'status' => (string) ($remote['status'] ?? 'draft'),
            'currency' => strtoupper((string) ($remote['currency'] ?? 'usd')),
            'subtotal_minor' => (int) ($remote['subtotal'] ?? 0),
            'tax_minor' => (int) ($remote['tax'] ?? 0),
            'total_minor' => (int) ($remote['total'] ?? 0),
            'amount_paid_minor' => (int) ($remote['amount_paid'] ?? 0),
            'amount_refunded_minor' => (int) ($charge['amount_refunded'] ?? 0),
            'description' => isset($line['description']) ? mb_substr((string) $line['description'], 0, 255) : null,
            'hosted_invoice_url' => $remote['hosted_invoice_url'] ?? null,
            'invoice_pdf' => $remote['invoice_pdf'] ?? null,
            'period_start' => $time($line['period']['start'] ?? $remote['period_start'] ?? null),
            'period_end' => $time($line['period']['end'] ?? $remote['period_end'] ?? null),
            'issued_at' => $time($remote['status_transitions']['finalized_at'] ?? $remote['created'] ?? null),
            'paid_at' => $time($remote['status_transitions']['paid_at'] ?? null),
        ]));
    }

    /**
     * One verified webhook event. Payloads are only triggers: the object is re-read from Stripe.
     *
     * @param  array<string, mixed>  $event
     */
    public function handleEvent(array $event): void
    {
        $object = (array) ($event['data']['object'] ?? []);
        $id = (string) ($object['id'] ?? '');
        $type = (string) $event['type'];

        match (true) {
            $type === 'checkout.session.completed' && ($object['mode'] ?? null) === 'subscription' && isset($object['subscription']) => $this->syncSubscription($this->stripe->get('subscriptions/'.$object['subscription'])),
            in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed'], true) => $this->syncSubscription($this->stripe->get("subscriptions/{$id}")),
            str_starts_with($type, 'invoice.') && $id !== '' => $this->syncInvoice($id),
            in_array($type, ['charge.refunded', 'charge.refund.updated'], true) => $this->syncRefund($type === 'charge.refunded' ? $id : (string) ($object['charge'] ?? '')),
            default => null,
        };
    }

    private function syncRefund(string $chargeId): void
    {
        if ($chargeId === '') {
            return;
        }
        $invoiceId = $this->stripe->get("charges/{$chargeId}")['invoice'] ?? null;
        if (is_string($invoiceId) && $invoiceId !== '') {
            $this->syncInvoice($invoiceId);
        }
    }

    // ── Stripe catalog (created on first use) ──────────────────────────────────────────────

    private function priceId(PlanVersion $version, string $interval): string
    {
        $column = $interval === 'yearly' ? 'stripe_price_yearly_id' : 'stripe_price_monthly_id';
        if ($version->{$column} !== null) {
            return (string) $version->{$column};
        }

        $plan = $version->plan()->firstOrFail();
        $lookup = "engage_{$plan->key}_v{$version->version}_".($interval === 'yearly' ? 'year' : 'month');
        $existing = $this->stripe->get('prices', ['lookup_keys' => [$lookup], 'active' => 'true', 'limit' => 1])['data'][0]['id'] ?? null;

        $id = $existing ?? $this->stripe->post('prices', [
            'currency' => strtolower($version->currency),
            'unit_amount' => $interval === 'yearly' ? $version->price_yearly_minor : $version->price_monthly_minor,
            'recurring' => ['interval' => $interval === 'yearly' ? 'year' : 'month'],
            'tax_behavior' => 'exclusive',
            'lookup_key' => $lookup,
            'product_data' => ['name' => "10X Engage {$plan->name}", 'metadata' => ['plan' => $plan->key]],
        ], "price:{$lookup}")['id'];

        $version->forceFill([$column => (string) $id])->save();

        return (string) $id;
    }

    private function uaeTaxRateId(): string
    {
        $configured = (string) config('engage.stripe.uae_tax_rate_id');
        if ($configured !== '') {
            return $configured;
        }

        return (string) Cache::rememberForever('stripe:uae_vat_tax_rate', function (): string {
            $percent = (float) config('engage.stripe.uae_vat_percent', 5);
            foreach ((array) ($this->stripe->get('tax_rates', ['active' => 'true', 'limit' => 100])['data'] ?? []) as $rate) {
                if (($rate['metadata']['engage'] ?? null) === 'uae_vat' && (float) $rate['percentage'] === $percent && ! $rate['inclusive']) {
                    return (string) $rate['id'];
                }
            }

            return (string) $this->stripe->post('tax_rates', [
                'display_name' => 'VAT',
                'description' => 'UAE VAT',
                'percentage' => $percent,
                'inclusive' => 'false',
                'country' => self::VAT_COUNTRY,
                'jurisdiction' => 'United Arab Emirates',
                'metadata' => ['engage' => 'uae_vat'],
            ], 'tax-rate:uae-vat:'.$percent)['id'];
        });
    }

    // ── Lookups ────────────────────────────────────────────────────────────────────────────

    public function liveStripeSubscription(Tenant $tenant): ?Subscription
    {
        return $this->context->bypass(fn () => Subscription::query()->where('tenant_id', $tenant->id)->live()
            ->where('provider', BillingProvider::Stripe->value)->whereNotNull('provider_subscription_id')->first());
    }

    private function tenantFor(mixed $tenantId, mixed $customerId): ?Tenant
    {
        return $this->context->bypass(function () use ($tenantId, $customerId): ?Tenant {
            $byId = is_string($tenantId) && $tenantId !== '' ? Tenant::query()->find($tenantId) : null;

            return $byId ?? (is_string($customerId) && $customerId !== '' ? Tenant::query()->where('stripe_customer_id', $customerId)->first() : null);
        });
    }
}
