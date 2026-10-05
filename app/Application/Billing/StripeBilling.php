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
            'address' => $tenant->country !== null ? ['country' => strtoupper($tenant->country)] : null,
            'metadata' => ['tenant_id' => $tenant->id, 'workspace' => $tenant->name],
        ]);

        if ($tenant->stripe_customer_id === null) {
            $customer = $this->stripe->post('customers', $params, "customer:{$tenant->id}");
            $this->context->bypass(fn () => $tenant->forceFill(['stripe_customer_id' => (string) $customer['id']])->save());
        } else {
            $this->stripe->post("customers/{$tenant->stripe_customer_id}", $params);
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

    // ── Starting / changing a subscription ─────────────────────────────────────────────────

    /**
     * Move the workspace to a paid plan. With no Stripe subscription yet this returns a Stripe
     * Checkout URL; with one, the plan is switched in place (prorated) and no redirect is needed.
     *
     * @return array{url: ?string, updated: bool}
     */
    public function subscribe(Tenant $tenant, string $planKey, string $interval, ?TenantMembership $actor = null): array
    {
        $version = Plan::query()->where('key', $planKey)->where('is_public', true)->first()?->activeVersion();
        $amount = $interval === 'yearly' ? $version?->price_yearly_minor : $version?->price_monthly_minor;
        if ($version === null || $amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['plan' => 'This plan cannot be bought online. Contact sales.']);
        }
        if ($tenant->country === null) {
            throw ValidationException::withMessages(['country' => 'Choose your billing country first, so the correct tax is applied.']);
        }

        $price = $this->priceId($version, $interval);
        $customer = $this->syncCustomer($tenant, $actor?->user()->value('email'));
        $taxRates = self::vatApplies($tenant) ? [$this->uaeTaxRateId()] : [];

        $live = $this->liveStripeSubscription($tenant);
        if ($live !== null) {
            $remote = $this->stripe->get("subscriptions/{$live->provider_subscription_id}");
            $item = $remote['items']['data'][0]['id'] ?? null;
            $updated = $this->stripe->post("subscriptions/{$live->provider_subscription_id}", [
                'items' => [['id' => $item, 'price' => $price]],
                'proration_behavior' => 'create_prorations',
                'cancel_at_period_end' => 'false',
                'default_tax_rates' => $taxRates === [] ? '' : $taxRates,
                'metadata' => ['tenant_id' => $tenant->id, 'plan_version_id' => $version->id],
            ]);
            $this->syncSubscription($updated);

            return ['url' => null, 'updated' => true];
        }

        $frontend = (string) config('engage.frontend_url');
        $session = $this->stripe->post('checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $customer,
            'client_reference_id' => $tenant->id,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'subscription_data' => array_filter([
                'metadata' => ['tenant_id' => $tenant->id, 'plan_version_id' => $version->id],
                'default_tax_rates' => $taxRates ?: null,
            ]),
            'success_url' => "{$frontend}/settings?tab=plan&checkout=success",
            'cancel_url' => "{$frontend}/settings?tab=plan&checkout=cancelled",
            'allow_promotion_codes' => 'true',
        ]);

        $this->audit->record('billing.checkout_started', $tenant, meta: ['plan' => $planKey, 'interval' => $interval]);

        return ['url' => (string) $session['url'], 'updated' => false];
    }

    /** Stripe's hosted page for payment methods, invoices and cancelling. */
    public function portalUrl(Tenant $tenant): string
    {
        if ($tenant->stripe_customer_id === null) {
            throw ValidationException::withMessages(['billing' => 'There is no payment history yet. Choose a plan first.']);
        }

        return (string) $this->stripe->post('billing_portal/sessions', [
            'customer' => $tenant->stripe_customer_id,
            'return_url' => config('engage.frontend_url').'/settings?tab=plan',
        ])['url'];
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
        $version = PlanVersion::query()->where('stripe_price_monthly_id', $priceId)->orWhere('stripe_price_yearly_id', $priceId)->first()
            ?? PlanVersion::query()->find($remote['metadata']['plan_version_id'] ?? null);
        if ($version === null) {
            return;
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
