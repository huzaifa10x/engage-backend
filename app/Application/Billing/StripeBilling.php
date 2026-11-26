<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Notifications\WorkspaceMailer;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Enums\BillingProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Plans\Models\PlanVersionFeature;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentReceiptNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Everything money-related happens on Stripe-hosted pages (Batch 6): Checkout for the first paid
 * plan, the Customer Portal for plan changes, cards, cancelling and paying. This class creates those
 * sessions and mirrors Stripe's state back into subscriptions / invoices when Stripe's webhooks say
 * something changed. Webhooks are the source of truth; a redirect back from Stripe never grants a plan.
 *
 * VAT: prices exclude 5% UAE VAT. With STRIPE_AUTOMATIC_TAX on, Stripe Tax works it out; otherwise
 * the UAE VAT tax rate applies to UAE billing addresses. Company name and TRN live on the Stripe
 * customer (collected by Checkout / the portal) so they are printed on every invoice.
 */
final class StripeBilling
{
    public const VAT_COUNTRY = 'AE';

    /** @var array<string, bool> subscription id → exists in the Stripe account in use (per request) */
    private array $known = [];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly SubscriptionService $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly WorkspaceMailer $mailer,
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

    // ── Stripe-hosted flows (Batch 6) ──────────────────────────────────────────────────────
    //
    // Every money action happens on a Stripe-hosted page: Checkout for the first paid plan, the
    // Customer Portal for plan changes, cards, cancelling and paying an open invoice. engageX only
    // creates the session and sends the client there; the plan changes when Stripe's webhook says
    // so (handleEvent). No card details, and no card ids, are kept by engageX.

    /**
     * Stripe Checkout for a workspace's first paid plan (from Free or a trial).
     *
     * The Stripe customer is created by Checkout itself, so a workspace that never upgrades has none.
     */
    public function checkout(Tenant $tenant, string $planKey, string $interval, ?TenantMembership $actor = null): string
    {
        [$version, $plan] = $this->purchasable($planKey, $interval);
        if ($this->liveStripeSubscription($tenant) !== null) {
            throw ValidationException::withMessages(['plan' => 'This workspace already has a paid plan. Change it with "Change plan".']);
        }
        $this->assertSeatsFit($tenant, $version, $plan);

        $automaticTax = (bool) config('engage.stripe.automatic_tax');
        if (! $automaticTax && $tenant->country === null) {
            // Without Stripe Tax the VAT rate is chosen from the billing country, so it must be known first.
            throw ValidationException::withMessages(['country' => 'Choose your billing country first (Billing details), so the correct VAT is charged.']);
        }
        $customer = $this->existingCustomer($tenant);
        $email = $tenant->billing_email ?: $actor?->user()->value('email');
        $base = (string) config('engage.frontend_url');

        $session = $this->withCatalog($version, fn (): array => $this->stripe->post('checkout/sessions', array_filter([
            'mode' => 'subscription',
            'client_reference_id' => $tenant->id,
            'customer' => $customer,
            'customer_email' => $customer === null && is_string($email) && $email !== '' ? $email : null,
            // A returning customer: Checkout saves the address and company name it collects back onto them.
            'customer_update' => $customer !== null ? ['address' => 'auto', 'name' => 'auto'] : null,
            'line_items' => [array_filter([
                'price' => $this->priceId($version, $interval),
                'quantity' => 1,
                // Without Stripe Tax: the 5% UAE VAT rate for a UAE workspace (billing country), none otherwise.
                // If Checkout's address says another country, checkoutCompleted() puts the subscription right.
                'tax_rates' => ! $automaticTax && self::vatApplies($tenant) ? [$this->uaeTaxRateId()] : null,
            ])],
            'automatic_tax' => $automaticTax ? ['enabled' => 'true'] : null,
            'tax_id_collection' => ['enabled' => 'true'],
            'billing_address_collection' => 'required',
            'subscription_data' => ['metadata' => ['tenant_id' => $tenant->id, 'plan_version_id' => $version->id]],
            'metadata' => ['tenant_id' => $tenant->id, 'plan' => $plan->key, 'interval' => $interval],
            'success_url' => $base.'/billing/return?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base.'/billing?canceled=1',
        ]), // A double click within the same minute opens the same Checkout, not a second subscription.
            'checkout:'.$tenant->id.':'.$version->id.':'.$interval.':'.intdiv(time(), 60)));

        $this->audit->record('billing.checkout_started', $tenant, meta: ['plan' => $plan->key, 'interval' => $interval]);

        return (string) $session['url'];
    }

    /**
     * A Customer Portal session for one task.
     *
     *  update  confirm a switch to $planKey / $interval (prorated now for upgrades; downgrades and
     *          Annual → Monthly are scheduled for the end of the period by the portal configuration)
     *  card    add or replace the card; an open invoice is paid with it on return (afterCardUpdate)
     *  cancel  cancel at the end of the paid period; the workspace then moves to Free
     *  manage  the portal home: undo a scheduled change or cancellation, invoices, billing details
     */
    public function portal(Tenant $tenant, string $flow, ?string $planKey = null, ?string $interval = null): string
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($live === null || $tenant->stripe_customer_id === null) {
            throw ValidationException::withMessages(['billing' => 'There is no paid plan to manage yet. Choose a plan to upgrade.']);
        }
        $subscriptionId = (string) $live->provider_subscription_id;
        $return = (string) config('engage.frontend_url').'/billing/return?flow='.$flow;
        $after = ['type' => 'redirect', 'redirect' => ['return_url' => $return]];

        $flowData = match ($flow) {
            'update' => $this->updateFlow($tenant, $live, (string) $planKey, (string) $interval) + ['after_completion' => $after],
            'card' => ['type' => 'payment_method_update', 'after_completion' => $after],
            'cancel' => $live->cancel_at !== null
                ? throw ValidationException::withMessages(['billing' => 'Your plan is already set to end on '.$live->cancel_at->format('j M Y').'.'])
                : ['type' => 'subscription_cancel', 'subscription_cancel' => ['subscription' => $subscriptionId], 'after_completion' => $after],
            'manage' => null,
            default => throw ValidationException::withMessages(['flow' => 'Unknown billing action.']),
        };

        $session = $this->stripe->post('billing_portal/sessions', array_filter([
            'customer' => $tenant->stripe_customer_id,
            'configuration' => $this->portalConfiguration(),
            'return_url' => $return,
            'flow_data' => $flowData,
        ]));
        $this->audit->record('billing.portal_opened', $tenant, meta: array_filter(['flow' => $flow, 'plan' => $planKey, 'interval' => $interval]));

        return (string) $session['url'];
    }

    /**
     * The client came back from Checkout. The redirect proves nothing by itself, so the session is
     * read from Stripe and checked against the signed-in workspace. Nothing is granted here: the
     * plan changes when checkout.session.completed / invoice.paid arrive (the page polls the summary).
     *
     * @return 'paid'|'processing'|'incomplete'|'rejected'
     */
    public function confirmCheckout(Tenant $tenant, string $sessionId): string
    {
        try {
            $session = $this->stripe->get("checkout/sessions/{$sessionId}");
        } catch (StripeException $e) {
            Log::warning('Billing return rejected: Checkout session not readable.', ['tenant_id' => $tenant->id, 'session' => $sessionId, 'error' => $e->getMessage()]);

            return 'rejected';
        }

        if (($session['client_reference_id'] ?? null) !== $tenant->id || ($session['mode'] ?? null) !== 'subscription') {
            Log::warning('Billing return rejected: the Checkout session belongs to another workspace.', ['tenant_id' => $tenant->id, 'session' => $sessionId]);
            $this->audit->record('billing.return_rejected', $tenant, meta: ['session' => $sessionId]);

            return 'rejected';
        }

        return match (true) {
            ($session['status'] ?? null) === 'complete' && in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true) => 'paid',
            ($session['status'] ?? null) === 'complete' => 'processing',   // e.g. the bank is still confirming
            ($session['status'] ?? null) === 'open' => 'incomplete',
            default => 'rejected',                                          // expired
        };
    }

    /**
     * Back from the portal's card screen: the new card becomes the subscription's card and any open
     * invoice is retried with it straight away (Batch 6, "Pay now").
     *
     * @return array{status: 'paid'|'nothing_due'|'failed', message: ?string}
     */
    public function afterCardUpdate(Tenant $tenant): array
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($live === null || $tenant->stripe_customer_id === null) {
            return ['status' => 'nothing_due', 'message' => null];
        }
        $subscriptionId = (string) $live->provider_subscription_id;
        $card = $this->stripe->get("customers/{$tenant->stripe_customer_id}")['invoice_settings']['default_payment_method'] ?? null;
        if (is_string($card) && $card !== '') {
            // A card set on the subscription itself would win over the customer's new default.
            $this->stripe->post("subscriptions/{$subscriptionId}", ['default_payment_method' => $card]);
        }

        $result = ['status' => 'nothing_due', 'message' => null];
        foreach ((array) ($this->stripe->get('invoices', ['subscription' => $subscriptionId, 'status' => 'open', 'limit' => 5])['data'] ?? []) as $invoice) {
            try {
                $this->stripe->post("invoices/{$invoice['id']}/pay", [], 'pay:'.$invoice['id'].':'.(is_string($card) ? $card : 'default'));
                $result = ['status' => 'paid', 'message' => null];
                $this->audit->record('billing.invoice_paid', $tenant, meta: ['invoice' => $invoice['number'] ?? $invoice['id']]);
            } catch (StripeException $e) {
                $result = ['status' => 'failed', 'message' => $e->getMessage()];
            }
            $this->syncInvoice((string) $invoice['id']);
        }
        $this->syncSubscription($this->stripe->get("subscriptions/{$subscriptionId}"));

        return $result;
    }

    /** Back from any other portal task: read the subscription from Stripe now (the webhook follows). */
    public function resync(Tenant $tenant): void
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($live !== null) {
            $this->syncSubscription($this->stripe->get('subscriptions/'.$live->provider_subscription_id));
        }
    }

    /**
     * The card renewals are charged to, as Stripe reports it (shown on the billing page and used for
     * expiry reminders). Read from Stripe each time; never stored.
     *
     * @return ?array{brand: string, last4: string, exp_month: int, exp_year: int}
     */
    public function paymentMethod(Tenant $tenant): ?array
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($tenant->stripe_customer_id === null) {
            return null;
        }
        try {
            $card = $live !== null
                ? ($this->stripe->get('subscriptions/'.$live->provider_subscription_id, ['expand' => ['default_payment_method']])['default_payment_method'] ?? null)
                : null;
            if (! is_array($card)) {
                $card = $this->stripe->get("customers/{$tenant->stripe_customer_id}", ['expand' => ['invoice_settings.default_payment_method']])['invoice_settings']['default_payment_method'] ?? null;
            }
        } catch (StripeException) {
            return null;
        }
        if (! is_array($card) || ! isset($card['card'])) {
            return null;
        }

        return [
            'brand' => (string) ($card['card']['brand'] ?? 'card'),
            'last4' => (string) ($card['card']['last4'] ?? ''),
            'exp_month' => (int) ($card['card']['exp_month'] ?? 0),
            'exp_year' => (int) ($card['card']['exp_year'] ?? 0),
        ];
    }

    /** A fresh link to the invoice PDF on Stripe (Stripe's links expire, so none is kept long-term). */
    public function invoicePdf(Invoice $invoice): ?string
    {
        $url = $this->stripe->get("invoices/{$invoice->stripe_invoice_id}")['invoice_pdf'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Blocks a switch, before Stripe opens, to a plan with fewer seats than the workspace uses.
     */
    private function assertSeatsFit(Tenant $tenant, PlanVersion $version, Plan $plan): void
    {
        $row = PlanVersionFeature::query()->where('plan_version_id', $version->id)
            ->whereHas('feature', fn ($q) => $q->where('key', FeatureKey::TeamSeats->value))->first();
        if ($row === null || ! $row->enabled || $row->limit_value === null) {
            return;
        }
        $used = (int) $this->entitlements->usage($tenant, FeatureKey::TeamSeats);
        if ($used > $row->limit_value) {
            $seats = $row->limit_value === 1 ? '1 team seat' : "{$row->limit_value} team seats";
            throw ValidationException::withMessages(['plan' => "{$plan->name} includes {$seats} and this workspace uses {$used} (members and pending invitations). Remove members or invitations first, then change plan."]);
        }
    }

    /** @return array<string, mixed> portal flow_data for confirming a switch to another price */
    private function updateFlow(Tenant $tenant, Subscription $live, string $planKey, string $interval): array
    {
        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            throw ValidationException::withMessages(['interval' => 'Choose monthly or annual billing.']);
        }
        [$version, $plan] = $this->purchasable($planKey, $interval);
        $this->assertSeatsFit($tenant, $version, $plan);

        $remote = $this->stripe->get('subscriptions/'.$live->provider_subscription_id);
        $item = $remote['items']['data'][0] ?? null;
        $price = $this->withCatalog($version, fn (): string => $this->priceId($version, $interval));
        if (! is_array($item)) {
            throw ValidationException::withMessages(['billing' => 'Stripe returned this subscription without a plan. Contact support.']);
        }
        if (($item['price']['id'] ?? null) === $price) {
            throw ValidationException::withMessages(['plan' => 'This workspace is already on '.$plan->name.' ('.($interval === 'yearly' ? 'annual' : 'monthly').').']);
        }

        return ['type' => 'subscription_update_confirm', 'subscription_update_confirm' => [
            'subscription' => (string) $live->provider_subscription_id,
            'items' => [['id' => (string) $item['id'], 'price' => $price, 'quantity' => 1]],
        ]];
    }

    /** The workspace's Stripe customer, if it has one that exists with the keys in use. */
    private function existingCustomer(Tenant $tenant): ?string
    {
        if ($tenant->stripe_customer_id === null) {
            return null;
        }
        try {
            $customer = $this->stripe->get("customers/{$tenant->stripe_customer_id}");

            return ($customer['deleted'] ?? false) ? null : (string) $tenant->stripe_customer_id;
        } catch (StripeException $e) {
            if ($e->stripeCode !== 'resource_missing') {
                throw $e;
            }

            return null; // other Stripe mode (test ↔ live): Checkout creates a fresh customer
        }
    }

    /**
     * The Customer Portal configuration for self-serve plans: switching between every public plan's
     * monthly and annual price, upgrades charged (prorated) at once, downgrades and Annual → Monthly
     * at the end of the period, cancellation at the end of the period with reasons. Created or updated
     * in Stripe whenever the set of prices changes. STRIPE_PORTAL_CONFIGURATION pins one instead.
     */
    private function portalConfiguration(): string
    {
        $pinned = (string) config('engage.stripe.portal_configuration');
        if ($pinned !== '') {
            return $pinned;
        }

        $prices = [];
        foreach (Plan::query()->where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get() as $plan) {
            $version = $plan->activeVersion();
            foreach (['monthly' => $version?->price_monthly_minor, 'yearly' => $version?->price_yearly_minor] as $interval => $amount) {
                if ($version !== null && $amount !== null && $amount > 0) {
                    $prices[] = $this->withCatalog($version, fn (): string => $this->priceId($version, $interval));
                }
            }
        }
        $hash = substr(sha1(implode(',', $prices)), 0, 16);

        return (string) Cache::rememberForever("stripe:portal_configuration:{$hash}", function () use ($prices, $hash): string {
            $products = [];
            foreach ($prices as $price) {
                $product = (string) $this->stripe->get("prices/{$price}")['product'];
                $products[$product][] = $price;
            }
            $params = [
                'business_profile' => ['headline' => '10X Engage billing'],
                'default_return_url' => (string) config('engage.frontend_url').'/billing',
                'features' => [
                    'customer_update' => ['enabled' => 'true', 'allowed_updates' => ['name', 'email', 'address', 'tax_id']],
                    'invoice_history' => ['enabled' => 'true'],
                    'payment_method_update' => ['enabled' => 'true'],
                    'subscription_cancel' => [
                        'enabled' => 'true',
                        'mode' => 'at_period_end',
                        'proration_behavior' => 'none',
                        'cancellation_reason' => ['enabled' => 'true', 'options' => ['too_expensive', 'missing_features', 'switched_service', 'unused', 'other']],
                    ],
                    'subscription_update' => [
                        'enabled' => 'true',
                        'default_allowed_updates' => ['price'],
                        'proration_behavior' => 'always_invoice',
                        'products' => array_map(fn (string $product, array $ids) => ['product' => $product, 'prices' => $ids], array_keys($products), array_values($products)),
                        'schedule_at_period_end' => ['conditions' => [['type' => 'decreasing_item_amount'], ['type' => 'shortening_interval']]],
                    ],
                ],
                'metadata' => ['engage' => 'self_serve', 'prices' => $hash],
            ];

            foreach ((array) ($this->stripe->get('billing_portal/configurations', ['active' => 'true', 'limit' => 100])['data'] ?? []) as $existing) {
                if (($existing['metadata']['engage'] ?? null) === 'self_serve') {
                    return (string) $this->stripe->post("billing_portal/configurations/{$existing['id']}", $params)['id'];
                }
            }

            return (string) $this->stripe->post('billing_portal/configurations', $params, "portal-configuration:{$hash}")['id'];
        });
    }

    /**
     * Billing details changed in Checkout or the portal (company name, email, address, TRN): copy them
     * onto the workspace so the billing page shows what Stripe will print on invoices.
     */
    public function syncCustomerDetails(string $customerId): void
    {
        $tenant = $this->tenantFor(null, $customerId);
        if ($tenant === null) {
            return;
        }
        $customer = $this->stripe->get("customers/{$customerId}", ['expand' => ['tax_ids']]);
        if ($customer['deleted'] ?? false) {
            return;
        }
        $trn = null;
        foreach ((array) ($customer['tax_ids']['data'] ?? []) as $taxId) {
            if (($taxId['type'] ?? null) === 'ae_trn') {
                $trn = (string) $taxId['value'];
            }
        }
        $address = (array) ($customer['address'] ?? []);
        $country = isset($address['country']) && is_string($address['country']) ? strtoupper($address['country']) : null;
        $countryChanged = $country !== null && $country !== $tenant->country;

        $this->context->bypass(fn () => $tenant->forceFill(array_filter([
            'legal_name' => $customer['name'] ?? null,
            'billing_email' => $customer['email'] ?? null,
            'country' => $country,
            'address_line1' => $address['line1'] ?? null,
            'address_line2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? null,
            'region' => $address['state'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '') + ['tax_trn' => $country === self::VAT_COUNTRY ? $trn : $tenant->tax_trn])->save());
        if ($countryChanged) {
            $this->applyTaxToSubscription($tenant); // VAT follows the billing country (no-op with Stripe Tax)
        }
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
     * @return ?array{amount_due_minor: int, total_minor: int, credit_applied_minor: int, currency: string, date: ?string}
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
        $total = (int) ($invoice['total'] ?? $invoice['amount_due'] ?? 0);

        return [
            'amount_due_minor' => (int) ($invoice['amount_due'] ?? 0),
            // The renewal price, and how much of it the wallet credit pays.
            'total_minor' => $total,
            'credit_applied_minor' => max(0, $total - (int) ($invoice['amount_due'] ?? 0)),
            'currency' => strtoupper((string) ($invoice['currency'] ?? 'usd')),
            'date' => is_numeric($at) ? Carbon::createFromTimestamp((int) $at)->toIso8601String() : null,
        ];
    }

    /**
     * The workspace's wallet: credit on its Stripe customer balance. It comes from the unused part
     * of a plan after a downgrade (never paid back to the card) and Stripe takes it off the next
     * invoices by itself: renewals, upgrades and interval changes alike.
     *
     * @return array{balance_minor: int, currency: string, entries: list<array<string, mixed>>}
     */
    public function wallet(Tenant $tenant): array
    {
        $empty = ['balance_minor' => 0, 'currency' => 'USD', 'entries' => []];
        if ($tenant->stripe_customer_id === null) {
            return $empty;
        }
        try {
            $customer = $this->stripe->get("customers/{$tenant->stripe_customer_id}");
            $history = (array) ($this->stripe->get("customers/{$tenant->stripe_customer_id}/balance_transactions", ['limit' => 20])['data'] ?? []);
        } catch (StripeException) {
            return $empty;
        }
        $currency = strtoupper((string) ($customer['currency'] ?? 'usd'));

        return [
            // Stripe keeps credit as a negative balance.
            'balance_minor' => max(0, -(int) ($customer['balance'] ?? 0)),
            'currency' => $currency,
            'entries' => array_map(fn (array $t) => [
                'id' => (string) ($t['id'] ?? ''),
                // Positive = credit added to the wallet, negative = credit used on an invoice.
                'amount_minor' => -(int) ($t['amount'] ?? 0),
                'balance_after_minor' => max(0, -(int) ($t['ending_balance'] ?? 0)),
                'kind' => -(int) ($t['amount'] ?? 0) >= 0 ? 'added' : 'used',
                'currency' => strtoupper((string) ($t['currency'] ?? $currency)),
                'created_at' => isset($t['created']) ? Carbon::createFromTimestamp((int) $t['created'])->toIso8601String() : null,
            ], array_values($history)),
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

    /** The company is being deleted: end the paid subscription in Stripe straight away (no refund). */
    public function cancelNow(Tenant $tenant): void
    {
        $live = $this->liveStripeSubscription($tenant);
        if ($live === null) {
            return;
        }
        $this->stripe->delete("subscriptions/{$live->provider_subscription_id}");
        $this->audit->record('billing.cancelled_for_deletion', $tenant);
    }

    /** After the billing country changed: add or remove VAT on the running subscription. */
    public function applyTaxToSubscription(Tenant $tenant): void
    {
        $live = (bool) config('engage.stripe.automatic_tax') ? null : $this->liveStripeSubscription($tenant);
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

        $scheduled = $this->scheduledChange($remote, $priceId);
        // The subscription can arrive before checkout.session.completed: link the customer here too.
        if ($tenant->stripe_customer_id === null && is_string($remote['customer'] ?? null)) {
            $this->context->bypass(fn () => $tenant->forceFill(['stripe_customer_id' => $remote['customer']])->save());
        }

        $this->context->run($tenant, function () use ($tenant, $remote, $version, $status, $priceId, $time, $cancelAt, $scheduled): void {
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
                // A downgrade or Annual → Monthly switch waiting for the end of the period (portal schedule).
                'scheduled_plan_version_id' => $scheduled['version'] ?? null,
                'scheduled_interval' => $scheduled['interval'] ?? null,
                'scheduled_change_at' => $scheduled['at'] ?? null,
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
            $type === 'checkout.session.completed' => $this->checkoutCompleted($id),
            in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed'], true) => $this->syncSubscription($this->stripe->get("subscriptions/{$id}")),
            str_starts_with($type, 'subscription_schedule.') && is_string($object['subscription'] ?? null) => $this->syncSubscription($this->stripe->get('subscriptions/'.$object['subscription'])),
            str_starts_with($type, 'invoice.') && $id !== '' => $this->invoiceEvent($type, $id, $object),
            in_array($type, ['charge.refunded', 'charge.refund.updated'], true) => $this->syncRefund($type === 'charge.refunded' ? $id : (string) ($object['charge'] ?? '')),
            $type === 'customer.updated' => $this->syncCustomerDetails($id),
            str_starts_with($type, 'customer.tax_id.') && is_string($object['customer'] ?? null) => $this->syncCustomerDetails($object['customer']),
            default => null,
        };
    }

    /**
     * Mirrors the invoice, then sends the customer email that belongs to the event: a receipt
     * (VAT invoice) when it was paid, a notice the first time a payment fails. Each Stripe event
     * is processed once, so each email is sent once.
     *
     * @param  array<string, mixed>  $object
     */
    private function invoiceEvent(string $type, string $invoiceId, array $object): void
    {
        $this->syncInvoice($invoiceId);
        if (! in_array($type, ['invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required'], true)) {
            return;
        }
        // No grace period: a failed renewal turns the subscription past_due and paid features pause at
        // once (EntitlementService); invoice.paid brings them back. Re-read, never trust event order.
        if (is_string($object['subscription'] ?? null) && $object['subscription'] !== '') {
            $this->syncSubscription($this->stripe->get('subscriptions/'.$object['subscription']));
        }
        if ($type === 'invoice.payment_action_required') {
            $type = 'invoice.payment_failed'; // the same notice: the bank wants the owner to approve it
        }

        $invoice = $this->context->bypass(fn () => Invoice::query()->where('stripe_invoice_id', $invoiceId)->first());
        $tenant = $invoice !== null ? $this->context->bypass(fn () => Tenant::query()->find($invoice->tenant_id)) : null;
        if ($invoice === null || $tenant === null) {
            return;
        }
        $money = fn (int $minor): string => $invoice->currency.' '.number_format($minor / 100, 2);

        if ($type === 'invoice.paid' && $invoice->amount_paid_minor > 0) {
            $this->mailer->toBilling($tenant, new PaymentReceiptNotification($tenant->legal_name ?: $tenant->name, $invoice->number, $money($invoice->subtotal_minor),
                $invoice->tax_minor > 0 ? $money($invoice->tax_minor) : null, $money($invoice->amount_paid_minor), $invoice->invoice_pdf, $tenant->tax_trn));
        }
        // First dunning notice only: Stripe retries several times, the customer gets one clear email.
        if ($type === 'invoice.payment_failed' && (int) ($object['attempt_count'] ?? 1) <= 1) {
            $retry = $object['next_payment_attempt'] ?? null;
            $this->mailer->toBilling($tenant, new PaymentFailedNotification($tenant->legal_name ?: $tenant->name, $invoice->number, $money($invoice->total_minor),
                is_numeric($retry) ? Carbon::createFromTimestamp((int) $retry)->format('j F Y') : null));
        }
    }

    /**
     * Checkout finished: link the Stripe customer to the workspace named in client_reference_id
     * (re-read from Stripe), then mirror the new subscription.
     */
    private function checkoutCompleted(string $sessionId): void
    {
        $session = $this->stripe->get("checkout/sessions/{$sessionId}");
        if (($session['mode'] ?? null) !== 'subscription' || ! is_string($session['subscription'] ?? null)) {
            return;
        }
        $tenant = $this->context->bypass(fn () => is_string($session['client_reference_id'] ?? null) ? Tenant::query()->find($session['client_reference_id']) : null);
        if ($tenant === null) {
            return; // not one of ours
        }
        if (is_string($session['customer'] ?? null) && $tenant->stripe_customer_id !== $session['customer']) {
            $this->context->bypass(fn () => $tenant->forceFill(['stripe_customer_id' => $session['customer']])->save());
            $this->syncCustomerDetails($session['customer']);
        }
        $this->syncSubscription($this->stripe->get('subscriptions/'.$session['subscription']));
        // The address entered on Checkout may be in another country than the one set beforehand:
        // renewals then carry the right VAT (no-op with Stripe Tax).
        $this->applyTaxToSubscription($this->context->bypass(fn () => $tenant->refresh()));
    }

    /**
     * The next phase of the subscription's schedule, when it changes the price (a downgrade or an
     * Annual → Monthly switch the portal scheduled for the end of the period).
     *
     * @param  array<string, mixed>  $remote
     * @return ?array{version: string, interval: string, at: Carbon}
     */
    private function scheduledChange(array $remote, string $currentPrice): ?array
    {
        $scheduleId = is_array($remote['schedule'] ?? null) ? ($remote['schedule']['id'] ?? null) : ($remote['schedule'] ?? null);
        if (! is_string($scheduleId) || $scheduleId === '') {
            return null;
        }
        $schedule = $this->stripe->get("subscription_schedules/{$scheduleId}");
        if (! in_array($schedule['status'] ?? '', ['not_started', 'active'], true)) {
            return null;
        }
        foreach ((array) ($schedule['phases'] ?? []) as $phase) {
            $price = $phase['items'][0]['price'] ?? null;
            $price = is_array($price) ? ($price['id'] ?? null) : $price;
            if ((int) ($phase['start_date'] ?? 0) <= now()->timestamp || ! is_string($price) || $price === $currentPrice) {
                continue;
            }
            $version = PlanVersion::query()->where('stripe_price_monthly_id', $price)->orWhere('stripe_price_yearly_id', $price)->orderByDesc('version')->first();
            if ($version === null) {
                return null;
            }

            return ['version' => (string) $version->id, 'interval' => $price === $version->stripe_price_yearly_id ? 'yearly' : 'monthly', 'at' => Carbon::createFromTimestamp((int) $phase['start_date'])];
        }

        return null;
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

    /**
     * The workspace's running Stripe subscription, as long as the Stripe account in use knows it.
     *
     * A subscription made with other keys (test ↔ live, or another Stripe account) does not exist
     * here: it is ignored, so the next plan choice starts a fresh subscription, which then replaces
     * it. The workspace keeps its current plan until then; nothing is cancelled just by looking.
     */
    public function liveStripeSubscription(Tenant $tenant): ?Subscription
    {
        $live = $this->context->bypass(fn () => Subscription::query()->where('tenant_id', $tenant->id)->live()
            ->where('provider', BillingProvider::Stripe->value)->whereNotNull('provider_subscription_id')->first());
        if ($live === null) {
            return null;
        }

        $id = (string) $live->provider_subscription_id;
        if (! array_key_exists($id, $this->known)) {
            try {
                $this->stripe->get("subscriptions/{$id}");
                $this->known[$id] = true;
            } catch (StripeException $e) {
                if ($e->stripeCode !== 'resource_missing') {
                    throw $e;
                }
                Log::warning('Stripe subscription not found with the current keys; treating the workspace as unsubscribed.', ['tenant_id' => $tenant->id, 'subscription' => $id]);
                $this->known[$id] = false;
            }
        }

        return $this->known[$id] ? $live : null;
    }

    private function tenantFor(mixed $tenantId, mixed $customerId): ?Tenant
    {
        return $this->context->bypass(function () use ($tenantId, $customerId): ?Tenant {
            $byId = is_string($tenantId) && $tenantId !== '' ? Tenant::query()->find($tenantId) : null;

            return $byId ?? (is_string($customerId) && $customerId !== '' ? Tenant::query()->where('stripe_customer_id', $customerId)->first() : null);
        });
    }
}
