<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Access\SystemRole;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Entitlements\ReplySeats;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\BillingReminderNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentReceiptNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Batch 6: billing through Stripe-hosted pages, against a small stateful fake of the Stripe API.
 * Every money action opens Checkout or the Customer Portal; only Stripe's webhooks change the plan.
 * The numbered comments refer to the brief's tests 25–37.
 */
final class StripeBillingTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private Tenant $tenant;

    private TenantMembership $owner;

    /** @var array<string, array<string, mixed>> checkout sessions by id */
    private array $sessions = [];

    /** @var array<string, mixed> */
    private array $remoteSubscription = [];

    /** @var array<string, mixed> */
    private array $remoteInvoice = [];

    /** @var array<string, mixed> */
    private array $remoteCustomer = ['id' => 'cus_1', 'name' => null, 'email' => null, 'address' => null, 'invoice_settings' => ['default_payment_method' => null], 'tax_ids' => ['data' => []]];

    /** @var ?array<string, mixed> the subscription schedule the portal made for a downgrade */
    private ?array $schedule = null;

    private bool $invoicePayFails = false;

    /** @var array{0: int, 1: int} month, year of the card renewals are charged to */
    private array $cardExpiry = [12, 2030];

    /** @var list<array<string, mixed>> portal configurations created or updated */
    private array $portalConfigurations = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['engage.stripe.key' => 'pk_test_x', 'engage.stripe.secret' => 'sk_test_x', 'engage.stripe.webhook_secret' => self::SECRET, 'engage.frontend_url' => 'https://app.engage.test']);
        Cache::flush();
        $this->tenant = $this->createTenant(['country' => 'AE']);
        app(SubscriptionService::class)->startNewWorkspace($this->tenant);
        $this->owner = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner);

        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => fn (Request $r) => Http::response(...$this->stripe($r))]);
    }

    /** @return array{0: array<string, mixed>, 1?: int} */
    private function stripe(Request $r): array
    {
        $path = (string) preg_replace('#^https://api\.stripe\.com/v1/#', '', explode('?', $r->url())[0]);
        $method = $r->method();

        return match (true) {
            $path === 'checkout/sessions' && $method === 'POST' => [$this->createSession($r)],
            (bool) preg_match('#^checkout/sessions/(cs_\w+)$#', $path, $m) => isset($this->sessions[$m[1]]) ? [$this->sessions[$m[1]]] : [['error' => ['code' => 'resource_missing', 'message' => 'No such checkout.session']], 404],
            $path === 'billing_portal/sessions' => [['id' => 'bps_1', 'url' => 'https://billing.stripe.com/p/session/test_1']],
            $path === 'billing_portal/configurations' && $method === 'GET' => [['data' => []]],
            $path === 'billing_portal/configurations' => [$this->portalConfiguration($r)],
            $path === 'customers/cus_1' && $method === 'POST' => [$this->remoteCustomer],
            $path === 'customers/cus_1' => [$this->customer($r)],
            $path === 'customers/cus_1/tax_ids' => [$method === 'GET' ? ['data' => []] : ['id' => 'txi_1']],
            $path === 'customers/cus_1/balance_transactions' => [['data' => []]],
            $path === 'prices' => [$method === 'GET' ? ['data' => []] : ['id' => 'price_'.($r['recurring']['interval'] ?? 'x').'_'.$r['unit_amount']]],
            (bool) preg_match('#^prices/(price_old_\w+)$#', $path, $m) => [['error' => ['code' => 'resource_missing', 'message' => "No such price: '{$m[1]}'"]], 404],
            (bool) preg_match('#^prices/(price_\w+)$#', $path, $m) => [['id' => $m[1], 'product' => 'prod_'.$m[1]]],
            $path === 'tax_rates' => [$method === 'GET' ? ['data' => []] : ['id' => 'txr_uae']],
            $path === 'subscriptions/sub_1' => [$this->subscription($r)],
            $path === 'subscriptions/sub_other_mode' => [['error' => ['code' => 'resource_missing', 'message' => "No such subscription: 'sub_other_mode'"]], 404],
            $path === 'subscription_schedules/sub_sched_1' => [(array) $this->schedule],
            $path === 'invoices' => [['data' => ($this->remoteInvoice['status'] ?? null) === 'open' ? [$this->remoteInvoice] : []]],
            $path === 'invoices/upcoming' => [['currency' => 'usd', 'total' => 7900, 'amount_due' => 7900, 'next_payment_attempt' => now()->addMonth()->timestamp]],
            $path === 'invoices/in_1/pay' => $this->invoicePayFails
                ? [['error' => ['code' => 'card_declined', 'message' => 'Your card was declined.']], 402]
                : [$this->remoteInvoice = array_merge($this->remoteInvoice, ['status' => 'paid', 'amount_paid' => $this->remoteInvoice['total']])],
            $path === 'invoices/in_1' => [$this->remoteInvoice],
            $path === 'charges' => [['data' => []]],
            $path === 'charges/ch_1' => [['id' => 'ch_1', 'invoice' => 'in_1']],
            default => [['error' => ['message' => "Unexpected Stripe call: {$method} {$path}"]], 400],
        };
    }

    /** @return array<string, mixed> */
    private function createSession(Request $r): array
    {
        $id = 'cs_test_'.(count($this->sessions) + 1);

        return $this->sessions[$id] = [
            'id' => $id, 'object' => 'checkout.session', 'mode' => $r['mode'], 'status' => 'open', 'payment_status' => 'unpaid',
            'client_reference_id' => $r['client_reference_id'], 'customer' => null, 'subscription' => null,
            'url' => "https://checkout.stripe.com/c/pay/{$id}", '__request' => $r->data(),
        ];
    }

    /** The client pays on the Checkout page: Stripe creates the customer and the subscription. */
    private function payCheckout(string $sessionId, string $status = 'active'): void
    {
        $request = $this->sessions[$sessionId]['__request'];
        $price = (string) $request['line_items'][0]['price'];
        $this->remoteSubscription = [
            'id' => 'sub_1', 'status' => $status, 'customer' => 'cus_1', 'cancel_at_period_end' => false, 'cancel_at' => null, 'schedule' => null,
            'collection_method' => 'charge_automatically', 'current_period_start' => now()->timestamp,
            'current_period_end' => (str_contains($price, 'year') ? now()->addYear() : now()->addMonth())->timestamp,
            'metadata' => $request['subscription_data']['metadata'], 'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => $price]]]],
        ];
        $this->remoteCustomer = array_merge($this->remoteCustomer, ['name' => 'Nova Fitness LLC', 'email' => 'billing@nova.test',
            'address' => ['country' => 'AE', 'city' => 'Dubai', 'line1' => 'Office 12'], 'tax_ids' => ['data' => [['type' => 'ae_trn', 'value' => '100200300400003']]]]);
        $this->sessions[$sessionId] = array_merge($this->sessions[$sessionId], [
            'status' => 'complete', 'payment_status' => $status === 'active' ? 'paid' : 'unpaid', 'customer' => 'cus_1', 'subscription' => 'sub_1',
        ]);
    }

    /** @return array<string, mixed> */
    private function customer(Request $r): array
    {
        $customer = $this->remoteCustomer;
        if (str_contains(urldecode($r->url()), 'invoice_settings.default_payment_method') && $customer['invoice_settings']['default_payment_method'] !== null) {
            $customer['invoice_settings']['default_payment_method'] = ['id' => 'pm_new', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => $this->cardExpiry[0], 'exp_year' => $this->cardExpiry[1]]];
        }

        return $customer;
    }

    /** @return array<string, mixed> */
    private function subscription(Request $r): array
    {
        if ($r->method() === 'POST') {
            $this->remoteSubscription['default_payment_method'] = $r['default_payment_method'] ?? ($this->remoteSubscription['default_payment_method'] ?? null);
        }

        return $this->remoteSubscription;
    }

    /** @return array<string, mixed> */
    private function portalConfiguration(Request $r): array
    {
        $this->portalConfigurations[] = $r->data();

        return ['id' => 'bpc_1'];
    }

    /** @param array<string, mixed> $object */
    private function stripeEvent(string $type, array $object, ?string $id = null, ?string $secret = null): TestResponse
    {
        $payload = (string) json_encode(['id' => $id ?? 'evt_'.bin2hex(random_bytes(6)), 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $secret ?? self::SECRET),
        ], $payload);
    }

    /** Upgrade through Checkout and let the webhook activate it, as a paying client would. */
    private function upgrade(string $plan = 'growth', string $interval = 'monthly'): void
    {
        $this->postJson('/api/v1/billing/checkout', ['plan' => $plan, 'interval' => $interval])->assertOk();
        $session = (string) array_key_last($this->sessions);
        $this->payCheckout($session);
        $this->stripeEvent('checkout.session.completed', ['id' => $session])->assertOk();
    }

    private function openInvoice(): void
    {
        $this->remoteInvoice = [
            'id' => 'in_1', 'number' => 'ENG-0001', 'status' => 'open', 'currency' => 'usd', 'customer' => 'cus_1', 'subscription' => 'sub_1',
            'subtotal' => 7900, 'tax' => 395, 'total' => 8295, 'amount_paid' => 0, 'created' => now()->timestamp, 'attempt_count' => 1,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/1', 'invoice_pdf' => 'https://pay.stripe.com/invoice/1/pdf?s=old',
            'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => null],
            'lines' => ['data' => [['description' => '1 × 10X Engage Growth', 'period' => ['start' => now()->timestamp, 'end' => now()->addMonth()->timestamp]]]],
            'charge' => ['id' => 'ch_1', 'amount_refunded' => 0],
        ];
    }

    private function planKey(): string
    {
        return (string) $this->getJson('/api/v1/billing/summary')->assertOk()->json('data.plan.key');
    }

    public function test_new_workspaces_can_start_on_free_without_a_card_or_a_stripe_customer(): void
    {
        config(['engage.plans.trial_days' => 0]);
        $tenant = $this->createTenant();
        app(SubscriptionService::class)->startNewWorkspace($tenant);
        $this->actingAsMember($this->addMember($tenant));

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.has_payment_history', false)->assertJsonPath('data.stripe_configured', true);
        $this->assertNull($this->tenantContext()->bypass(fn () => $tenant->fresh())?->stripe_customer_id);
        Http::assertNothingSent();
    }

    public function test_upgrading_opens_stripe_checkout_and_only_the_webhook_activates_the_plan(): void
    {
        // Upgrade: a Checkout Session, nothing created by engageX itself (no customer, no subscription).
        $url = $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk()->json('data.url');
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $url);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['mode'] === 'subscription'
            && $r['client_reference_id'] === $this->tenant->id && $r['line_items'][0]['price'] === 'price_month_7900'
            && $r['tax_id_collection']['enabled'] === 'true' && $r['billing_address_collection'] === 'required'
            && $r['success_url'] === 'https://app.engage.test/billing/return?session_id={CHECKOUT_SESSION_ID}'
            && $r['cancel_url'] === 'https://app.engage.test/billing?canceled=1'
            && $r['subscription_data']['metadata']['tenant_id'] === $this->tenant->id && $r->hasHeader('Idempotency-Key'));
        Http::assertNotSent(fn (Request $r) => preg_match('#/v1/(customers|subscriptions)$#', explode('?', $r->url())[0]) === 1);
        $this->assertSame('pro', $this->planKey()); // still the trial

        // 25: paid, browser closed before the redirect → the webhook alone activates the plan.
        $this->payCheckout('cs_test_1');
        $this->stripeEvent('checkout.session.completed', ['id' => 'cs_test_1'])->assertOk();
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.plan.key', 'growth')->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.interval', 'monthly')->assertJsonPath('data.subscription.self_serve', true)
            // Billing details collected by Checkout are copied onto the workspace (TRN included).
            ->assertJsonPath('data.details.legal_name', 'Nova Fitness LLC')->assertJsonPath('data.details.country', 'AE')->assertJsonPath('data.details.tax_trn', '100200300400003');
        $this->assertSame('cus_1', $this->tenantContext()->bypass(fn () => $this->tenant->fresh())?->stripe_customer_id);

        // A second Checkout while a paid plan runs is refused: changes go through the portal.
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])->assertStatus(422);
    }

    public function test_uae_vat_is_set_from_the_billing_country_and_follows_the_address_given_at_checkout(): void
    {
        // Without Stripe Tax the country decides the VAT, so it is asked for first.
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['country' => null])->save());
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(422)->assertJsonPath('error.details.fields.country.0', fn ($m) => str_contains((string) $m, 'billing country'));
        Http::assertNothingSent();

        // UAE: the 5% VAT rate on the Checkout line (tax_rates; Stripe no longer accepts dynamic_tax_rates).
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['country' => 'AE'])->save());
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['line_items'][0]['tax_rates'] === ['txr_uae']
            && ! isset($r['line_items'][0]['dynamic_tax_rates']) && ! isset($r['automatic_tax']));

        // Elsewhere: no VAT.
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['country' => 'PK'])->save());
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'starter', 'interval' => 'monthly'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['line_items'][0]['price'] === 'price_month_2900' && ! isset($r['line_items'][0]['tax_rates']));

        // The address entered on Checkout is in the UAE: renewals carry the VAT from now on.
        $this->payCheckout('cs_test_2');
        $this->stripeEvent('checkout.session.completed', ['id' => 'cs_test_2'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.details.country', 'AE')->assertJsonPath('data.vat.applies', true)->assertJsonPath('data.vat.automatic', false);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions/sub_1') && $r->method() === 'POST' && ($r['default_tax_rates'] ?? null) === ['txr_uae']);

        // With Stripe Tax on, no country is needed first and Stripe works VAT out.
        config(['engage.stripe.automatic_tax' => true]);
        $other = $this->createTenant();
        app(SubscriptionService::class)->startNewWorkspace($other);
        $this->actingAsMember($this->addMember($other));
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'yearly'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && ($r['automatic_tax']['enabled'] ?? null) === 'true' && ! isset($r['line_items'][0]['tax_rates']));
    }

    public function test_the_return_from_checkout_is_checked_with_stripe_and_grants_nothing(): void
    {
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'starter', 'interval' => 'yearly'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['line_items'][0]['price'] === 'price_year_29000');

        // Not paid yet (closed the tab, or still on Stripe).
        $this->postJson('/api/v1/billing/return', ['session_id' => 'cs_test_1'])->assertOk()->assertJsonPath('data.status', 'incomplete');

        // Paid: the return says so, but the plan only changes when the webhook arrives.
        $this->payCheckout('cs_test_1');
        $this->postJson('/api/v1/billing/return', ['session_id' => 'cs_test_1'])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('pro', $this->planKey());
        $this->stripeEvent('checkout.session.completed', ['id' => 'cs_test_1'])->assertOk();
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.plan.key', 'starter')->assertJsonPath('data.subscription.interval', 'yearly');

        // 26: a session from another workspace is rejected and changes nothing.
        $other = $this->createTenant();
        $this->actingAsMember($this->addMember($other));
        $this->postJson('/api/v1/billing/return', ['session_id' => 'cs_test_1'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.plan.key', 'free');
        $this->postJson('/api/v1/billing/return', ['session_id' => 'not-a-session'])->assertStatus(422);
        $this->postJson('/api/v1/billing/return', ['session_id' => 'cs_test_missing'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_a_declined_or_unconfirmed_first_payment_leaves_the_workspace_where_it_was(): void
    {
        // 27 / 28: Stripe shows the decline or the 3-D Secure step on its own page. Until the bank
        // confirms, the subscription is incomplete and nothing changes here.
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk();
        $this->payCheckout('cs_test_1', 'incomplete');
        $this->stripeEvent('checkout.session.completed', ['id' => 'cs_test_1'])->assertOk();
        $this->postJson('/api/v1/billing/return', ['session_id' => 'cs_test_1'])->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertSame('pro', $this->planKey());

        // The bank confirms: the plan activates.
        $this->remoteSubscription['status'] = 'active';
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->assertSame('growth', $this->planKey());
    }

    public function test_plan_changes_open_the_portal_confirm_screen_with_the_chosen_price(): void
    {
        $this->upgrade('growth');

        // 29 / 30: Growth Monthly → Pro Monthly, and Growth Monthly → Growth Annual.
        $url = $this->postJson('/api/v1/billing/portal', ['flow' => 'update', 'plan' => 'pro', 'interval' => 'monthly'])->assertOk()->json('data.url');
        $this->assertSame('https://billing.stripe.com/p/session/test_1', $url);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/billing_portal/sessions') && $r['customer'] === 'cus_1' && $r['configuration'] === 'bpc_1'
            && $r['flow_data']['type'] === 'subscription_update_confirm' && $r['flow_data']['subscription_update_confirm']['subscription'] === 'sub_1'
            && $r['flow_data']['subscription_update_confirm']['items'][0] === ['id' => 'si_1', 'price' => 'price_month_13900', 'quantity' => 1]
            && $r['flow_data']['after_completion']['redirect']['return_url'] === 'https://app.engage.test/billing/return?flow=update');
        $this->postJson('/api/v1/billing/portal', ['flow' => 'update', 'plan' => 'growth', 'interval' => 'yearly'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/billing_portal/sessions')
            && ($r['flow_data']['subscription_update_confirm']['items'][0]['price'] ?? null) === 'price_year_79000');

        // The portal configuration: upgrades prorated and charged now; downgrades and Annual → Monthly at period end.
        $config = $this->portalConfigurations[0];
        $this->assertSame('always_invoice', $config['features']['subscription_update']['proration_behavior']);
        $this->assertSame([['type' => 'decreasing_item_amount'], ['type' => 'shortening_interval']], $config['features']['subscription_update']['schedule_at_period_end']['conditions']);
        $this->assertSame('at_period_end', $config['features']['subscription_cancel']['mode']);
        $this->assertCount(6, array_merge(...array_column($config['features']['subscription_update']['products'], 'prices'))); // 3 plans × monthly + annual
        $this->postJson('/api/v1/billing/portal', ['flow' => 'manage'])->assertOk();
        $this->assertCount(1, $this->portalConfigurations); // created once, then reused

        // The plan it is already on is refused before Stripe opens.
        $this->postJson('/api/v1/billing/portal', ['flow' => 'update', 'plan' => 'growth', 'interval' => 'monthly'])->assertStatus(422);

        // Back from the portal: Stripe's subscription is read again (the webhook follows).
        $this->remoteSubscription['items']['data'][0]['price']['id'] = 'price_month_13900';
        $this->postJson('/api/v1/billing/return', ['flow' => 'update'])->assertOk()->assertJsonPath('data.status', 'synced');
        $this->assertSame('pro', $this->planKey());
    }

    public function test_prices_left_over_from_other_stripe_keys_are_replaced_when_the_portal_opens(): void
    {
        $this->upgrade('growth');
        // Prices stored while other Stripe keys were in use: this account does not know them.
        foreach (['starter', 'pro'] as $key) {
            Plan::byKey($key)->activeVersionOrFail()->forceFill(['stripe_price_monthly_id' => "price_old_{$key}_m", 'stripe_price_yearly_id' => "price_old_{$key}_y"])->save();
        }

        $this->postJson('/api/v1/billing/portal', ['flow' => 'manage'])->assertOk();
        $this->assertSame('price_month_13900', Plan::byKey('pro')->activeVersionOrFail()->stripe_price_monthly_id);
        $this->assertSame('price_year_29000', Plan::byKey('starter')->activeVersionOrFail()->stripe_price_yearly_id);
        $prices = array_merge(...array_column($this->portalConfigurations[0]['features']['subscription_update']['products'], 'prices'));
        $this->assertCount(6, $prices);
        $this->assertSame([], array_filter($prices, fn (string $p) => str_starts_with($p, 'price_old_')));

        // Switching plan uses this account's price too.
        Plan::byKey('starter')->activeVersionOrFail()->forceFill(['stripe_price_monthly_id' => 'price_old_starter_m'])->save();
        $this->postJson('/api/v1/billing/portal', ['flow' => 'update', 'plan' => 'starter', 'interval' => 'monthly'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/billing_portal/sessions')
            && ($r['flow_data']['subscription_update_confirm']['items'][0]['price'] ?? null) === 'price_month_2900');

        // Checked once: opening the portal again asks Stripe about no price.
        $before = count(Http::recorded(fn (Request $r) => str_contains($r->url(), '/v1/prices/')));
        $this->postJson('/api/v1/billing/portal', ['flow' => 'card'])->assertOk();
        $this->assertSame($before, count(Http::recorded(fn (Request $r) => str_contains($r->url(), '/v1/prices/'))));
    }

    public function test_a_downgrade_is_scheduled_for_the_end_of_the_period_and_shown(): void
    {
        $this->upgrade('pro', 'yearly');
        $this->postJson('/api/v1/billing/portal', ['flow' => 'manage'])->assertOk(); // the portal knows every plan's prices

        // 31: Pro Annual → Starter Monthly: $0 today; the portal schedules it for the period end.
        $at = now()->addMonths(11)->startOfDay();
        $this->remoteSubscription['schedule'] = 'sub_sched_1';
        $this->schedule = ['id' => 'sub_sched_1', 'status' => 'active', 'subscription' => 'sub_1', 'phases' => [
            ['start_date' => now()->subMonth()->timestamp, 'items' => [['price' => 'price_year_139000']]],
            ['start_date' => $at->timestamp, 'items' => [['price' => 'price_month_2900']]],
        ]];
        $this->stripeEvent('subscription_schedule.updated', ['id' => 'sub_sched_1', 'subscription' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.plan.key', 'pro')
            ->assertJsonPath('data.subscription.scheduled_change.plan.key', 'starter')->assertJsonPath('data.subscription.scheduled_change.interval', 'monthly')
            ->assertJsonPath('data.subscription.scheduled_change.at', $at->toIso8601String());

        // Undo in the portal releases the schedule: the pending change disappears.
        $this->schedule['status'] = 'released';
        $this->remoteSubscription['schedule'] = null;
        $this->stripeEvent('subscription_schedule.released', ['id' => 'sub_sched_1', 'subscription' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.subscription.scheduled_change', null);
    }

    public function test_a_downgrade_with_more_members_than_the_new_plan_allows_is_blocked_before_stripe(): void
    {
        $this->upgrade('growth'); // 5 seats
        $this->addMember($this->tenant, SystemRole::Agent);
        $this->addMember($this->tenant, SystemRole::Agent);

        // 32: Starter has 2 seats; 3 are used.
        $response = $this->postJson('/api/v1/billing/portal', ['flow' => 'update', 'plan' => 'starter', 'interval' => 'monthly'])->assertStatus(422);
        $this->assertStringContainsString('includes 2 team seats', (string) $response->json('error.details.fields.plan.0'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'billing_portal/sessions'));
    }

    public function test_a_failed_renewal_pauses_paid_features_at_once_and_a_new_card_pays_and_restores_them(): void
    {
        $this->upgrade('growth');
        Notification::fake();
        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->allows($this->tenant, FeatureKey::Chatbots));

        // 33: renewal fails → past_due → paid features off at once; messaging is not a paid feature.
        $this->openInvoice();
        $this->remoteSubscription['status'] = 'past_due';
        $this->stripeEvent('invoice.payment_failed', ['id' => 'in_1', 'subscription' => 'sub_1', 'attempt_count' => 1, 'next_payment_attempt' => now()->addDays(3)->timestamp])->assertOk();
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.subscription.status', 'past_due')->assertJsonPath('data.subscription.paused', true)->assertJsonPath('data.plan.key', 'growth');
        $this->assertFalse($entitlements->allows($this->tenant, FeatureKey::Chatbots));
        $this->assertFalse($entitlements->allows($this->tenant, FeatureKey::Broadcasts));
        $this->assertFalse($entitlements->allows($this->tenant, FeatureKey::Automations));
        $this->assertTrue($entitlements->allows($this->tenant, FeatureKey::WhatsappNumbers));
        Notification::assertSentOnDemandTimes(PaymentFailedNotification::class, 2); // the owner and the billing email from Checkout

        // 34: "Pay now" → the portal's card screen; back here the open invoice is paid with the new card.
        $this->postJson('/api/v1/billing/portal', ['flow' => 'card'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/billing_portal/sessions') && $r['flow_data']['type'] === 'payment_method_update'
            && $r['flow_data']['after_completion']['redirect']['return_url'] === 'https://app.engage.test/billing/return?flow=card');
        $this->remoteCustomer['invoice_settings']['default_payment_method'] = 'pm_new';
        $this->remoteSubscription['status'] = 'active';
        $this->postJson('/api/v1/billing/return', ['flow' => 'card'])->assertOk()->assertJsonPath('data.status', 'paid');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions/sub_1') && $r->method() === 'POST' && ($r['default_payment_method'] ?? null) === 'pm_new');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices/in_1/pay'));
        $this->getJson('/api/v1/billing/invoices')->assertJsonPath('data.0.status', 'paid');
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.subscription.paused', false);
        $this->assertTrue($entitlements->allows($this->tenant, FeatureKey::Chatbots));

        // The card shown on the page comes from Stripe, not from engageX.
        $this->getJson('/api/v1/billing/payment-method')->assertOk()->assertJsonPath('data.last4', '4242');
    }

    public function test_a_card_that_fails_again_keeps_the_features_paused(): void
    {
        $this->upgrade('growth');
        $this->openInvoice();
        $this->remoteSubscription['status'] = 'past_due';
        $this->stripeEvent('invoice.payment_failed', ['id' => 'in_1', 'subscription' => 'sub_1'])->assertOk();

        $this->invoicePayFails = true;
        $this->postJson('/api/v1/billing/return', ['flow' => 'card'])->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.message', 'Your card was declined.');
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.subscription.paused', true);
    }

    public function test_members_beyond_the_plans_seats_stay_but_cannot_reply(): void
    {
        $this->upgrade('starter'); // 2 seats
        $first = $this->addMember($this->tenant, SystemRole::Agent);
        $this->travel(1)->seconds();
        $second = $this->addMember($this->tenant, SystemRole::Agent);
        $seats = app(ReplySeats::class);

        $this->assertTrue($seats->canReply($this->tenant, $this->owner));
        $this->assertTrue($seats->canReply($this->tenant, $first));
        $this->assertFalse($seats->canReply($this->tenant, $second)); // 3rd member, 2 seats

        // Unpaid renewal: Free features, 1 seat: only the owner replies. Paid again: back to 2.
        $this->remoteSubscription['status'] = 'past_due';
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->assertTrue($seats->canReply($this->tenant, $this->owner));
        $this->assertFalse($seats->canReply($this->tenant, $first));
        $this->remoteSubscription['status'] = 'active';
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->assertTrue($seats->canReply($this->tenant, $first));
    }

    public function test_cancelling_goes_through_the_portal_and_ends_on_free_with_the_data_kept(): void
    {
        $this->upgrade('growth');
        $this->postJson('/api/v1/billing/switch-to-free')->assertStatus(422); // a paid plan is cancelled on Stripe's screen

        $this->postJson('/api/v1/billing/portal', ['flow' => 'cancel'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/billing_portal/sessions') && $r['flow_data']['type'] === 'subscription_cancel'
            && $r['flow_data']['subscription_cancel']['subscription'] === 'sub_1');

        $this->remoteSubscription['cancel_at_period_end'] = true;
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.plan.key', 'growth')->assertJsonPath('data.subscription.cancel_at', Carbon::createFromTimestamp($this->remoteSubscription['current_period_end'])->toIso8601String());
        $this->postJson('/api/v1/billing/portal', ['flow' => 'cancel'])->assertStatus(422); // already ending

        $this->remoteSubscription['status'] = 'canceled';
        $this->stripeEvent('customer.subscription.deleted', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.subscription.provider', 'manual');
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => Subscription::query()->live()->count()));
        $this->postJson('/api/v1/billing/portal', ['flow' => 'manage'])->assertStatus(422); // nothing paid to manage
    }

    public function test_a_trial_moves_to_free_straight_away(): void
    {
        $this->getJson('/api/v1/billing')->assertJsonPath('data.can_switch_to_free', true);
        $this->postJson('/api/v1/billing/switch-to-free')->assertOk()->assertJsonPath('data.plan.key', 'free');
    }

    public function test_invoices_are_mirrored_and_their_pdf_is_fetched_fresh_from_stripe(): void
    {
        $this->upgrade('growth');
        $this->openInvoice();
        $this->stripeEvent('invoice.finalized', ['id' => 'in_1'])->assertOk();
        $id = $this->getJson('/api/v1/billing/invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.has_pdf', true)->assertJsonMissingPath('data.0.invoice_pdf')->json('data.0.id');

        // 36: the PDF link comes from Stripe each time (Stripe rotates them).
        $this->remoteInvoice['invoice_pdf'] = 'https://pay.stripe.com/invoice/1/pdf?s=fresh';
        $this->getJson("/api/v1/billing/invoices/{$id}/pdf")->assertOk()->assertJsonPath('data.url', 'https://pay.stripe.com/invoice/1/pdf?s=fresh');

        // Refunds (issued by the platform team or in Stripe) are reflected.
        $this->remoteInvoice = array_merge($this->remoteInvoice, ['status' => 'paid', 'amount_paid' => 8295, 'charge' => ['id' => 'ch_1', 'amount_refunded' => 8295]]);
        $this->stripeEvent('charge.refunded', ['id' => 'ch_1'])->assertOk();
        $this->getJson('/api/v1/billing/invoices')->assertJsonPath('data.0.status', 'refunded');

        // Another workspace cannot read it.
        $this->actingAsMember($this->addMember($this->createTenant()));
        $this->getJson("/api/v1/billing/invoices/{$id}/pdf")->assertNotFound();
    }

    public function test_the_same_webhook_is_processed_once_and_receipts_are_sent_once(): void
    {
        $this->upgrade('growth');
        Notification::fake();
        $this->openInvoice();
        $this->remoteInvoice = array_merge($this->remoteInvoice, ['status' => 'paid', 'amount_paid' => 8295, 'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => now()->timestamp]]);

        // 37: delivered twice.
        $this->stripeEvent('invoice.paid', ['id' => 'in_1', 'subscription' => 'sub_1'], 'evt_paid')->assertOk();
        $this->stripeEvent('invoice.paid', ['id' => 'in_1', 'subscription' => 'sub_1'], 'evt_paid')->assertOk()->assertJsonPath('duplicate', true);
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 2); // once each: the owner and the billing email
        $this->assertSame(1, DB::table('stripe_events')->where('id', 'evt_paid')->count());

        // A failure releases the event so Stripe's retry processes it.
        $this->remoteInvoice = [];
        $this->stripeEvent('invoice.paid', ['id' => 'in_missing'], 'evt_broken')->assertStatus(500);
        $this->assertSame(0, DB::table('stripe_events')->where('id', 'evt_broken')->count());
    }

    public function test_billing_details_changed_in_stripe_are_copied_onto_the_workspace(): void
    {
        $this->upgrade('growth');
        $this->remoteCustomer = array_merge($this->remoteCustomer, ['name' => 'Nova Fitness FZ-LLC', 'email' => 'accounts@nova.test',
            'tax_ids' => ['data' => [['type' => 'ae_trn', 'value' => '100999888777666']]]]);
        $this->stripeEvent('customer.tax_id.created', ['id' => 'txi_2', 'customer' => 'cus_1'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.details.legal_name', 'Nova Fitness FZ-LLC')
            ->assertJsonPath('data.details.billing_email', 'accounts@nova.test')->assertJsonPath('data.details.tax_trn', '100999888777666');
    }

    public function test_reminders_go_out_before_the_card_expires_and_before_an_annual_renewal(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 6)->setTime(5, 17));
        $this->upgrade('growth', 'yearly');
        $this->remoteCustomer['invoice_settings']['default_payment_method'] = 'pm_new';
        $this->cardExpiry = [3, 2027]; // ends 31 March: 25 days away → the 30-day reminder
        $this->tenantContext()->run($this->tenant, fn () => Subscription::query()->live()->update(['current_period_end' => now()->addDays(7)->setTime(12, 0)]));
        Notification::fake();

        Artisan::call('engage:billing:reminders');
        Artisan::call('engage:billing:reminders'); // a second run sends nothing twice
        // Two reminders, each to the owner and the billing email.
        Notification::assertSentOnDemandTimes(BillingReminderNotification::class, 4);
        Notification::assertSentOnDemand(BillingReminderNotification::class, fn (BillingReminderNotification $n) => $n->kind === BillingReminderNotification::CARD_EXPIRING && $n->card === 'Visa ending 4242');
        Notification::assertSentOnDemand(BillingReminderNotification::class, fn (BillingReminderNotification $n) => $n->kind === BillingReminderNotification::ANNUAL_RENEWAL);
    }

    public function test_an_ended_subscription_falls_back_to_free_without_a_webhook(): void
    {
        $this->upgrade('growth');
        $this->remoteSubscription['status'] = 'canceled';
        $this->tenantContext()->run($this->tenant, fn () => Subscription::query()->live()->update(['current_period_end' => now()->subHours(3)]));
        Artisan::call('engage:billing:reconcile');
        $this->actingAsMember($this->owner);
        $this->assertSame('free', $this->planKey());
    }

    public function test_a_subscription_made_with_other_stripe_keys_does_not_block_upgrading(): void
    {
        $this->upgrade('starter');
        $bypass = fn (callable $q) => app(TenantContext::class)->bypass($q);
        $bypass(fn () => Subscription::query()->where('provider_subscription_id', 'sub_1')->update(['provider_subscription_id' => 'sub_other_mode']));

        // Stripe no longer knows it: the page does not offer the portal (which would refuse), but
        // Checkout, and the new subscription replaces the stale one.
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.plan.key', 'starter')
            ->assertJsonPath('data.subscription.provider', 'stripe')->assertJsonPath('data.subscription.self_serve', false);
        $this->postJson('/api/v1/billing/portal', ['flow' => 'manage'])->assertStatus(422);
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk();
        $session = (string) array_key_last($this->sessions);
        $this->payCheckout($session);
        $this->stripeEvent('checkout.session.completed', ['id' => $session])->assertOk();
        $this->assertSame('growth', $this->planKey());
        $this->assertSame(1, $bypass(fn () => Subscription::query()->where('tenant_id', $this->tenant->id)->live()->count()));
    }

    public function test_webhooks_must_be_signed_and_billing_actions_are_owner_only(): void
    {
        $this->stripeEvent('invoice.paid', ['id' => 'in_1'], null, 'whsec_wrong')->assertStatus(400);
        $this->postJson('/api/webhooks/stripe', ['id' => 'evt_x', 'type' => 'invoice.paid'])->assertStatus(400);

        // An event for a customer that is not ours is acknowledged and ignored.
        $this->remoteSubscription = ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_other', 'metadata' => [], 'items' => ['data' => [['id' => 'si', 'price' => ['id' => 'price_unknown']]]]];
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->assertSame('pro', $this->planKey());

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $this->getJson('/api/v1/billing')->assertOk();
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertForbidden();
        $this->postJson('/api/v1/billing/portal', ['flow' => 'card'])->assertForbidden();
        $this->postJson('/api/v1/billing/return', ['flow' => 'card'])->assertForbidden();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/billing')->assertForbidden();
    }

    public function test_without_stripe_keys_the_page_still_loads_and_buying_explains_why_not(): void
    {
        config(['engage.stripe.secret' => null]);

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.stripe_configured', false);
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(503)->assertJsonPath('error.code', 'billing_error');
    }
}
