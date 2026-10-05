<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Access\SystemRole;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class StripeBillingTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private Tenant $tenant;

    private TenantMembership $owner;

    /** @var array<string, mixed> the "current" Stripe subscription returned by GET /subscriptions/sub_1 */
    private array $remoteSubscription = [];

    /** @var array<string, mixed> */
    private array $remoteInvoice = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['engage.stripe.secret' => 'sk_test_x', 'engage.stripe.webhook_secret' => self::SECRET, 'engage.frontend_url' => 'https://app.test']);
        Cache::flush();
        $this->tenant = $this->createTenant();
        app(SubscriptionService::class)->startTrial($this->tenant); // like a freshly registered workspace: 14 days of Pro
        $this->owner = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner);

        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/customers/cus_1/tax_ids*' => fn (Request $r) => Http::response($r->method() === 'GET' ? ['data' => []] : ['id' => 'txi_1']),
            'api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/prices*' => fn (Request $r) => Http::response($r->method() === 'GET' ? ['data' => []] : ['id' => 'price_'.($r['recurring']['interval'] ?? 'x').'_'.$r['unit_amount']]),
            'api.stripe.com/v1/tax_rates*' => fn (Request $r) => Http::response($r->method() === 'GET' ? ['data' => []] : ['id' => 'txr_uae']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
            'api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/1']),
            'api.stripe.com/v1/subscriptions/sub_1' => fn (Request $r) => Http::response($this->remoteSubscription = array_merge($this->remoteSubscription,
                $r->method() === 'POST' && isset($r['cancel_at_period_end']) ? ['cancel_at_period_end' => $r['cancel_at_period_end'] === 'true'] : [])),
            'api.stripe.com/v1/invoices/in_1*' => fn () => Http::response($this->remoteInvoice),
            'api.stripe.com/v1/charges/ch_1' => Http::response(['id' => 'ch_1', 'invoice' => 'in_1']),
        ]);
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

    private function billingCountry(string $country, ?string $trn = null, ?string $company = null): void
    {
        $this->putJson('/api/v1/billing/details', ['country' => $country, 'tax_trn' => $trn, 'legal_name' => $company])->assertOk();
    }

    /** @param array<string, mixed> $overrides */
    private function activate(string $price = 'price_month_7900', array $overrides = []): void
    {
        $this->remoteSubscription = $overrides + [
            'id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1', 'cancel_at_period_end' => false, 'cancel_at' => null,
            'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp,
            'metadata' => ['tenant_id' => $this->tenant->id],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => $price]]]],
        ];
        $this->stripeEvent('customer.subscription.created', ['id' => 'sub_1'])->assertOk();
    }

    public function test_a_uae_customer_is_sent_to_checkout_with_vat_and_their_trn_on_the_invoice(): void
    {
        // The country must be known before paying, so the right tax is applied.
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(422)->assertJsonPath('error.details.fields.country.0', 'Choose your billing country first, so the correct tax is applied.');

        $this->putJson('/api/v1/billing/details', ['country' => 'AE', 'tax_trn' => '12345'])->assertStatus(422); // a UAE TRN has 15 digits
        $this->billingCountry('ae', '100-2003-0040-0003', 'Nova Fitness LLC');

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.vat.applies', true)->assertJsonPath('data.vat.percent', 5)
            ->assertJsonPath('data.details.tax_trn', '100200300400003')->assertJsonPath('data.details.country', 'AE');

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])
            ->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_1')->assertJsonPath('data.updated', false);

        // Customer carries the company name, country and TRN; the price is USD 79.00 exclusive of tax.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/customers' && $r['name'] === 'Nova Fitness LLC' && $r['address']['country'] === 'AE' && $r['metadata']['tenant_id'] === $this->tenant->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/customers/cus_1/tax_ids') && $r->method() === 'POST' && $r['type'] === 'ae_trn' && $r['value'] === '100200300400003');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/prices' && $r->method() === 'POST' && $r['currency'] === 'usd' && (int) $r['unit_amount'] === 7900 && $r['tax_behavior'] === 'exclusive' && $r['recurring']['interval'] === 'month');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/tax_rates' && $r->method() === 'POST' && (float) $r['percentage'] === 5.0 && $r['inclusive'] === 'false' && $r['country'] === 'AE');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['mode'] === 'subscription' && $r['customer'] === 'cus_1'
            && $r['line_items'][0]['price'] === 'price_month_7900' && $r['subscription_data']['default_tax_rates'] === ['txr_uae']
            && $r['subscription_data']['metadata']['tenant_id'] === $this->tenant->id && $r['success_url'] === 'https://app.test/settings?tab=plan&checkout=success');
        Http::assertSent(fn (Request $r) => $r->hasHeader('Stripe-Version', '2024-06-20') && $r->hasHeader('Authorization', 'Bearer sk_test_x'));
    }

    public function test_customers_outside_the_uae_are_not_charged_vat(): void
    {
        $this->billingCountry('PK');
        $this->getJson('/api/v1/billing')->assertJsonPath('data.vat.applies', false);

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'starter', 'interval' => 'yearly'])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && ! isset($r['subscription_data']['default_tax_rates']) && str_starts_with($r['line_items'][0]['price'], 'price_year_'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tax_rates'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/tax_ids') && $r->method() === 'POST');
    }

    public function test_stripe_webhooks_drive_the_subscription_through_its_whole_life(): void
    {
        $this->billingCountry('AE');
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro'); // still on the trial until Stripe confirms payment

        // Payment succeeded → Stripe says the subscription is active.
        $this->activate();
        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.plan.key', 'growth')->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.provider', 'stripe')->assertJsonPath('data.subscription.interval', 'monthly');
        $this->getJson('/api/v1/tenant/entitlements')->assertJsonPath('data.plan.key', 'growth');

        // A renewal payment fails → past due (the plan is kept while Stripe retries the card).
        $this->remoteSubscription['status'] = 'past_due';
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.subscription.status', 'past_due')->assertJsonPath('data.plan.key', 'growth');

        // Card fixed → active again; then the customer cancels at period end and changes their mind.
        $this->remoteSubscription['status'] = 'active';
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->postJson('/api/v1/billing/cancel')->assertOk()->assertJsonPath('data.subscription.cancel_at', fn (?string $at) => $at !== null);
        $this->postJson('/api/v1/billing/resume')->assertOk()->assertJsonPath('data.subscription.cancel_at', null);

        // Upgrade in place: no second checkout, the Stripe subscription is switched and prorated.
        $this->remoteSubscription['items']['data'][0]['price']['id'] = 'price_month_13900';
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])->assertOk()->assertJsonPath('data.updated', true)->assertJsonPath('data.url', null);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions/sub_1') && $r->method() === 'POST' && ($r['items'][0]['price'] ?? null) === 'price_month_13900'
            && $r['items'][0]['id'] === 'si_1' && $r['proration_behavior'] === 'create_prorations' && $r['default_tax_rates'] === ['txr_uae']);
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro');

        // The subscription ends on Stripe → the workspace moves to Free. Exactly one live subscription remains.
        $this->remoteSubscription['status'] = 'canceled';
        $this->stripeEvent('customer.subscription.deleted', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.subscription.provider', 'manual');
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => Subscription::query()->live()->count()));
    }

    public function test_invoices_and_refunds_are_mirrored_from_stripe(): void
    {
        $this->billingCountry('AE');
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk(); // creates cus_1

        $this->remoteInvoice = [
            'id' => 'in_1', 'number' => 'ENG-0001', 'status' => 'paid', 'currency' => 'usd', 'customer' => 'cus_1',
            'subtotal' => 7900, 'tax' => 395, 'total' => 8295, 'amount_paid' => 8295, 'created' => now()->timestamp,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/1', 'invoice_pdf' => 'https://pay.stripe.com/invoice/1/pdf',
            'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => now()->timestamp],
            'lines' => ['data' => [['description' => '1 × 10X Engage Growth (at $79.00 / month)', 'period' => ['start' => now()->timestamp, 'end' => now()->addMonth()->timestamp]]]],
            'charge' => ['id' => 'ch_1', 'amount_refunded' => 0],
        ];
        $this->stripeEvent('invoice.paid', ['id' => 'in_1'], 'evt_paid')->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->stripeEvent('invoice.paid', ['id' => 'in_1'], 'evt_paid')->assertOk()->assertJsonPath('duplicate', true); // Stripe retried

        $this->getJson('/api/v1/billing/invoices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'ENG-0001')->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.subtotal_minor', 7900)->assertJsonPath('data.0.tax_minor', 395)->assertJsonPath('data.0.total_minor', 8295)
            ->assertJsonPath('data.0.invoice_pdf', 'https://pay.stripe.com/invoice/1/pdf');

        // A refund issued in the Stripe Dashboard shows up here.
        $this->remoteInvoice['charge']['amount_refunded'] = 8295;
        $this->stripeEvent('charge.refunded', ['id' => 'ch_1'])->assertOk();
        $this->getJson('/api/v1/billing/invoices')->assertJsonPath('data.0.status', 'refunded')->assertJsonPath('data.0.amount_refunded_minor', 8295);

        $this->postJson('/api/v1/billing/portal')->assertOk()->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/1');
    }

    public function test_webhooks_must_be_signed_and_billing_is_isolated_and_owner_only(): void
    {
        $this->stripeEvent('invoice.paid', ['id' => 'in_1'], null, 'whsec_wrong')->assertStatus(400);
        $this->postJson('/api/webhooks/stripe', ['id' => 'evt_x', 'type' => 'invoice.paid'])->assertStatus(400);

        // An event for a customer that is not ours is acknowledged and ignored.
        $this->remoteSubscription = ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_other', 'metadata' => [], 'items' => ['data' => [['id' => 'si', 'price' => ['id' => 'price_unknown']]]]];
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro')->assertJsonPath('data.subscription.status', 'trialing');

        // Admins can see billing but only the owner can change it; agents see nothing.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $this->getJson('/api/v1/billing')->assertOk();
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertForbidden();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/billing')->assertForbidden();

        // Plans come from the catalog in USD; Enterprise (custom price) cannot be bought online.
        $this->actingAsMember($this->owner);
        $plans = collect($this->getJson('/api/v1/billing')->json('data.plans'))->keyBy('key');
        $this->assertSame(2900, $plans['starter']['price_monthly_minor']);
        $this->assertSame('USD', $plans['starter']['currency']);
        $this->assertFalse($plans['free']['purchasable']);
        $this->assertSame((bool) Plan::byKey('enterprise')->activeVersion()?->price_monthly_minor, $plans['enterprise']['purchasable'] ?? false);
    }

    public function test_without_stripe_keys_the_page_still_loads_and_buying_explains_why_not(): void
    {
        config(['engage.stripe.secret' => null]);
        $this->billingCountry('AE');

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.stripe_configured', false);
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(503)->assertJsonPath('error.code', 'billing_error');
    }
}
