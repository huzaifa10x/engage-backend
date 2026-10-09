<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Access\SystemRole;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentReceiptNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** In-app Stripe billing against a small stateful fake of the Stripe API. */
final class StripeBillingTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private Tenant $tenant;

    private TenantMembership $owner;

    /** @var array<string, array<string, mixed>> saved cards on cus_1 */
    private array $cards = [];

    private ?string $defaultCard = null;

    /** @var array<string, mixed> */
    private array $remoteSubscription = [];

    /** @var array<string, mixed> */
    private array $remoteInvoice = [];

    private string $intentStatus = 'requires_confirmation';

    private bool $changeNeedsAction = false;

    private bool $changeDeclined = false;

    /** @var array<string, mixed> the last upcoming-invoice query the app sent */
    private array $previewQuery = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['engage.stripe.key' => 'pk_test_x', 'engage.stripe.secret' => 'sk_test_x', 'engage.stripe.webhook_secret' => self::SECRET]);
        Cache::flush();
        $this->tenant = $this->createTenant();
        app(SubscriptionService::class)->startTrial($this->tenant); // like a freshly registered workspace: 14 days of Pro
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
            $path === 'customers' => [['id' => 'cus_1']],
            $path === 'customers/cus_1' && $method === 'POST' => [$this->updateCustomer($r)],
            $path === 'customers/cus_1' => [['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => $this->defaultCard]]],
            $path === 'customers/cus_1/tax_ids' => [$method === 'GET' ? ['data' => []] : ['id' => 'txi_1']],
            $path === 'customers/cus_1/payment_methods' => [['data' => array_values($this->cards)]],
            $path === 'setup_intents' => [['id' => 'seti_1', 'client_secret' => 'seti_1_secret_abc']],
            (bool) preg_match('#^payment_methods/(pm_\w+)/detach$#', $path, $m) => [$this->detach($m[1])],
            (bool) preg_match('#^payment_methods/(pm_\w+)$#', $path, $m) => [['id' => $m[1], 'customer' => isset($this->cards[$m[1]]) ? 'cus_1' : 'cus_someone_else']],
            $path === 'prices' => [$method === 'GET' ? ['data' => []] : ['id' => 'price_'.($r['recurring']['interval'] ?? 'x').'_'.$r['unit_amount']]],
            $path === 'tax_rates' => [$method === 'GET' ? ['data' => []] : ['id' => 'txr_uae']],
            $path === 'subscriptions' && $method === 'POST' => [$this->createSubscription($r)],
            $path === 'subscriptions' => [['data' => $this->remoteSubscription === [] ? [] : [$this->remoteSubscription]]],
            $path === 'subscriptions/sub_1' => [$this->subscription($r)],
            $path === 'invoices/upcoming' => [$this->upcoming($r)],
            $path === 'invoices/in_2/void' => [$this->voidPending()],
            $path === 'charges' => [['data' => [
                ['id' => 'ch_1', 'amount' => 8295, 'amount_refunded' => 0, 'refunded' => false, 'currency' => 'usd', 'status' => 'succeeded', 'created' => now()->timestamp,
                    'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']], 'receipt_url' => 'https://pay.stripe.com/receipts/1'],
            ]]],
            $path === 'invoices/in_1/pay' => [$this->remoteInvoice = array_merge($this->remoteInvoice, ['status' => 'paid', 'amount_paid' => $this->remoteInvoice['total']])],
            $path === 'invoices/in_1' => [$this->remoteInvoice],
            $path === 'charges/ch_1' => [['id' => 'ch_1', 'invoice' => 'in_1']],
            $path === 'refunds' => [$this->refund($r)],
            default => [['error' => ['message' => "Unexpected Stripe call: {$method} {$path}"]], 400],
        };
    }

    /** @return array<string, mixed> */
    private function updateCustomer(Request $r): array
    {
        $this->defaultCard = $r['invoice_settings']['default_payment_method'] ?? $this->defaultCard;

        return ['id' => 'cus_1'];
    }

    /** @return array<string, mixed> */
    private function detach(string $id): array
    {
        unset($this->cards[$id]);
        $this->defaultCard = $this->defaultCard === $id ? null : $this->defaultCard;

        return ['id' => $id];
    }

    /** @return array<string, mixed> */
    private function createSubscription(Request $r): array
    {
        $this->remoteSubscription = [
            'id' => 'sub_1', 'status' => 'incomplete', 'customer' => 'cus_1', 'cancel_at_period_end' => false, 'cancel_at' => null,
            'collection_method' => 'charge_automatically', 'default_tax_rates' => $r['default_tax_rates'] ?? [],
            'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp,
            'metadata' => $r['metadata'], 'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => $r['items'][0]['price']]]]],
        ];

        return $this->remoteSubscription + ['latest_invoice' => ['id' => 'in_1', 'payment_intent' => ['id' => 'pi_1', 'status' => $this->intentStatus, 'client_secret' => 'pi_1_secret_xyz']]];
    }

    /** What Stripe would invoice: a new subscription, or a prorated change starting a new period today. */
    private function upcoming(Request $r): array
    {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $price = (string) ($q['subscription_items'][0]['price'] ?? '');
        $amount = (int) substr($price, (int) strrpos($price, '_') + 1);
        $this->previewQuery = $q;

        if (! isset($q['subscription'])) {
            $tax = isset($q['subscription_default_tax_rates']) ? (int) round($amount * 0.05) : 0;

            return ['currency' => 'usd', 'subtotal' => $amount, 'tax' => $tax, 'total' => $amount + $tax, 'amount_due' => $amount + $tax, 'starting_balance' => 0, 'ending_balance' => 0,
                'lines' => ['data' => [['description' => '1 × plan', 'amount' => $amount, 'proration' => false]]]];
        }
        if (! isset($q['subscription_items'])) {
            return ['currency' => 'usd', 'amount_due' => 8295, 'next_payment_attempt' => now()->addMonth()->timestamp]; // plain renewal
        }

        // Half the current period is unused → half its price comes back as credit.
        $credit = (int) round($this->currentPrice() / 2);
        $subtotal = $amount - $credit;
        $tax = $subtotal > 0 && ! empty($this->remoteSubscription['default_tax_rates']) ? (int) round($subtotal * 0.05) : 0;

        return ['currency' => 'usd', 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax, 'amount_due' => max(0, $subtotal + $tax),
            'starting_balance' => 0, 'ending_balance' => min(0, $subtotal),
            'lines' => ['data' => [
                ['description' => 'Unused time on current plan', 'amount' => -$credit, 'proration' => true],
                ['description' => '1 × new plan', 'amount' => $amount, 'proration' => false],
            ]]];
    }

    private function currentPrice(): int
    {
        $price = (string) ($this->remoteSubscription['items']['data'][0]['price']['id'] ?? '_0');

        return (int) substr($price, (int) strrpos($price, '_') + 1);
    }

    /** @return array<string, mixed> */
    private function voidPending(): array
    {
        unset($this->remoteSubscription['pending_update']);
        $this->remoteSubscription['latest_invoice'] = 'in_2';

        return ['id' => 'in_2', 'status' => 'void'];
    }

    /** @return array<string, mixed> */
    private function subscription(Request $r): array
    {
        if ($r->method() === 'DELETE') {
            return $this->remoteSubscription = array_merge($this->remoteSubscription, ['status' => 'canceled']);
        }
        if ($r->method() === 'POST') {
            $this->remoteSubscription = array_merge($this->remoteSubscription, array_filter([
                'cancel_at_period_end' => isset($r['cancel_at_period_end']) ? $r['cancel_at_period_end'] === 'true' : null,
                'collection_method' => $r['collection_method'] ?? null,
            ], fn ($v) => $v !== null));
            if (isset($r['items'][0]['price'])) {
                // pending_if_incomplete: when the bank wants a verification step, the change waits for the payment.
                if ($this->changeNeedsAction || $this->changeDeclined) {
                    $this->remoteSubscription['pending_update'] = ['subscription_items' => [['price' => $r['items'][0]['price']]]];
                    $this->remoteSubscription['latest_invoice'] = 'in_2';

                    return array_merge($this->remoteSubscription, ['latest_invoice' => ['id' => 'in_2', 'payment_intent' => ['id' => 'pi_2', 'client_secret' => 'pi_2_secret',
                        'status' => $this->changeDeclined ? 'requires_payment_method' : 'requires_action', 'last_payment_error' => ['message' => 'Your card has insufficient funds.']]]]);
                }
                $this->remoteSubscription['items']['data'][0]['price']['id'] = $r['items'][0]['price'];
                $this->remoteSubscription['current_period_start'] = now()->timestamp;
                $this->remoteSubscription['current_period_end'] = (str_contains($r['items'][0]['price'], 'year') ? now()->addYear() : now()->addMonth())->timestamp;

                return array_merge($this->remoteSubscription, ['latest_invoice' => ['id' => 'in_2', 'payment_intent' => ['id' => 'pi_2', 'status' => 'succeeded', 'client_secret' => 'pi_2_secret']]]);
            }
        }

        return $this->remoteSubscription;
    }

    /** @return array<string, mixed> */
    private function refund(Request $r): array
    {
        $this->remoteInvoice['charge'] = 'ch_1';
        $this->remoteInvoice['__refunded'] = ($this->remoteInvoice['__refunded'] ?? 0) + (int) ($r['amount'] ?? $this->remoteInvoice['amount_paid']);

        return ['id' => 're_1'];
    }

    private function card(string $id = 'pm_visa', string $last4 = '4242'): void
    {
        $this->cards[$id] = ['id' => $id, 'card' => ['brand' => 'visa', 'last4' => $last4, 'exp_month' => 12, 'exp_year' => 2030]];
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

    /** Add a card and subscribe; the "browser" confirms the payment, then asks the server to refresh. */
    private function subscribeAndPay(string $plan = 'growth', string $interval = 'monthly'): void
    {
        $this->postJson('/api/v1/billing/payment-methods/setup-intent')->assertOk();
        $this->card();
        $this->postJson('/api/v1/billing/subscribe', ['plan' => $plan, 'interval' => $interval])->assertOk()->assertJsonPath('data.status', 'requires_confirmation');
        $this->remoteSubscription['status'] = 'active'; // stripe.confirmCardPayment succeeded in the browser
        $this->postJson('/api/v1/billing/refresh')->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_cards_are_added_in_app_and_managed_without_leaving_the_platform(): void
    {
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.stripe_configured', true)->assertJsonPath('data.stripe_publishable_key', 'pk_test_x');
        $this->getJson('/api/v1/billing/payment-methods')->assertOk()->assertJsonCount(0, 'data');

        // The browser gets a SetupIntent secret and completes the card with Stripe's fields.
        $this->postJson('/api/v1/billing/payment-methods/setup-intent')->assertOk()->assertJsonPath('data.client_secret', 'seti_1_secret_abc');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setup_intents') && $r['customer'] === 'cus_1' && $r['usage'] === 'off_session');

        $this->card('pm_visa', '4242');
        $this->getJson('/api/v1/billing/payment-methods')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last4', '4242')->assertJsonPath('data.0.brand', 'visa')->assertJsonPath('data.0.is_default', true); // first card becomes the default

        $this->card('pm_mc', '4444');
        $this->putJson('/api/v1/billing/payment-methods/pm_mc/default')->assertOk();
        $this->assertSame('pm_mc', $this->defaultCard);

        $this->deleteJson('/api/v1/billing/payment-methods/pm_mc')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'pm_visa');
        $this->assertSame('pm_visa', $this->defaultCard); // the remaining card was promoted

        // Somebody else's card id is refused.
        $this->putJson('/api/v1/billing/payment-methods/pm_stolen/default')->assertStatus(422);
        $this->deleteJson('/api/v1/billing/payment-methods/pm_stolen')->assertStatus(422);
    }

    public function test_a_uae_customer_subscribes_in_page_with_vat_and_their_trn(): void
    {
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(422); // country first
        $this->putJson('/api/v1/billing/details', ['country' => 'AE', 'tax_trn' => '12345'])->assertStatus(422);         // a UAE TRN has 15 digits
        $this->billingCountry('ae', '100-2003-0040-0003', 'Nova Fitness LLC');
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('error.details.fields.payment_method.0', 'Add a card first, then choose your plan.');

        $this->card();
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.status', 'requires_confirmation')->assertJsonPath('data.client_secret', 'pi_1_secret_xyz')->assertJsonPath('data.payment_method', 'pm_visa');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/customers' && $r['name'] === 'Nova Fitness LLC' && $r['address']['country'] === 'AE');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/customers/cus_1/tax_ids') && $r->method() === 'POST' && $r['type'] === 'ae_trn' && $r['value'] === '100200300400003');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/prices' && $r->method() === 'POST' && $r['currency'] === 'usd' && (int) $r['unit_amount'] === 7900 && $r['tax_behavior'] === 'exclusive');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions' && $r->method() === 'POST'
            && $r['customer'] === 'cus_1' && $r['default_payment_method'] === 'pm_visa' && $r['payment_behavior'] === 'default_incomplete'
            && $r['default_tax_rates'] === ['txr_uae'] && $r['items'][0]['price'] === 'price_month_7900' && $r['metadata']['tenant_id'] === $this->tenant->id);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'checkout/sessions') || str_contains($r->url(), 'billing_portal'));

        // Nothing changes until the payment has really succeeded.
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro')->assertJsonPath('data.subscription.status', 'trialing');
        $this->postJson('/api/v1/billing/refresh')->assertOk()->assertJsonPath('data.status', 'pending');

        $this->remoteSubscription['status'] = 'active';
        $this->postJson('/api/v1/billing/refresh')->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'growth')->assertJsonPath('data.subscription.provider', 'stripe')
            ->assertJsonPath('data.vat.applies', true);
    }

    public function test_a_declined_card_leaves_the_plan_unchanged_and_no_vat_outside_the_uae(): void
    {
        $this->billingCountry('PK');
        $this->card();
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'starter', 'interval' => 'yearly'])->assertOk()->assertJsonPath('data.status', 'requires_confirmation');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions' && $r->method() === 'POST' && ! isset($r['default_tax_rates']) && str_starts_with($r['items'][0]['price'], 'price_year_'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tax_rates'));

        // The browser reports the card was declined → the unpaid attempt is cancelled on Stripe.
        $this->postJson('/api/v1/billing/refresh', ['abandon' => true])->assertOk()->assertJsonPath('data.status', 'failed');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscriptions/sub_1'));
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro')->assertJsonPath('data.subscription.status', 'trialing');
    }

    public function test_the_subscription_follows_stripe_through_its_whole_life(): void
    {
        $this->billingCountry('AE');
        $this->subscribeAndPay();
        $this->getJson('/api/v1/tenant/entitlements')->assertJsonPath('data.plan.key', 'growth');

        // Renewal payment fails → past due, plan kept while Stripe retries.
        $this->remoteSubscription['status'] = 'past_due';
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.subscription.status', 'past_due')->assertJsonPath('data.plan.key', 'growth');
        $this->remoteSubscription['status'] = 'active';
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();

        // The only card of an active subscription cannot be removed.
        $this->deleteJson('/api/v1/billing/payment-methods/pm_visa')->assertStatus(422);

        // Cancel at period end, then a change of mind.
        $this->postJson('/api/v1/billing/cancel')->assertOk()->assertJsonPath('data.subscription.cancel_at', fn (?string $at) => $at !== null);
        $this->postJson('/api/v1/billing/resume')->assertOk()->assertJsonPath('data.subscription.cancel_at', null);

        // Ended on Stripe → Free, with exactly one live subscription.
        $this->remoteSubscription['status'] = 'canceled';
        $this->stripeEvent('customer.subscription.deleted', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.subscription.provider', 'manual');
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => Subscription::query()->live()->count()));
    }

    public function test_invoices_are_mirrored_paid_in_app_and_show_refunds(): void
    {
        $this->billingCountry('AE');
        $this->subscribeAndPay();

        $this->remoteInvoice = [
            'id' => 'in_1', 'number' => 'ENG-0001', 'status' => 'open', 'currency' => 'usd', 'customer' => 'cus_1',
            'subtotal' => 7900, 'tax' => 395, 'total' => 8295, 'amount_paid' => 0, 'created' => now()->timestamp,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/1', 'invoice_pdf' => 'https://pay.stripe.com/invoice/1/pdf',
            'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => null],
            'lines' => ['data' => [['description' => '1 × 10X Engage Growth (at $79.00 / month)', 'period' => ['start' => now()->timestamp, 'end' => now()->addMonth()->timestamp]]]],
            'charge' => ['id' => 'ch_1', 'amount_refunded' => 0],
        ];
        $this->stripeEvent('invoice.finalized', ['id' => 'in_1'], 'evt_fin')->assertOk();
        $this->stripeEvent('invoice.finalized', ['id' => 'in_1'], 'evt_fin')->assertOk()->assertJsonPath('duplicate', true); // Stripe retried

        $invoice = $this->getJson('/api/v1/billing/invoices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'open')->assertJsonPath('data.0.tax_minor', 395)->assertJsonPath('data.0.total_minor', 8295)->json('data.0.id');

        // "Pay now" with the saved card, without leaving the platform.
        $this->postJson("/api/v1/billing/invoices/{$invoice}/pay")->assertOk()->assertJsonPath('data.status', 'paid');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices/in_1/pay') && $r['payment_method'] === 'pm_visa');
        $this->getJson('/api/v1/billing/invoices')->assertJsonPath('data.0.status', 'paid');
        $this->postJson("/api/v1/billing/invoices/{$invoice}/pay")->assertStatus(422); // already paid

        // A refund (issued by the platform team or in Stripe) is reflected on the invoice.
        $this->remoteInvoice['charge']['amount_refunded'] = 8295;
        $this->stripeEvent('charge.refunded', ['id' => 'ch_1'])->assertOk();
        $this->getJson('/api/v1/billing/invoices')->assertJsonPath('data.0.status', 'refunded')->assertJsonPath('data.0.amount_refunded_minor', 8295);
    }

    public function test_the_billing_summary_comes_from_stripe_before_anything_is_charged(): void
    {
        $this->postJson('/api/v1/billing/preview', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(422); // country first
        $this->billingCountry('AE');

        // New subscription: plan price + 5% VAT, nothing created on Stripe except the preview.
        $this->postJson('/api/v1/billing/preview', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.change', false)->assertJsonPath('data.subtotal_minor', 7900)->assertJsonPath('data.tax_minor', 395)
            ->assertJsonPath('data.amount_due_minor', 8295)->assertJsonPath('data.has_payment_method', false)->assertJsonPath('data.plan.name', 'Growth');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions') && $r->method() === 'POST');
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro')->assertJsonPath('data.subscription.status', 'trialing');
    }

    public function test_a_plan_change_is_prorated_charged_immediately_and_starts_a_new_period(): void
    {
        $this->billingCountry('PK'); // no VAT, to keep the arithmetic plain
        $this->subscribeAndPay('starter'); // $29 / month

        // Upgrade to Growth ($79): half of Starter is unused → $14.50 credit → $64.50 due today.
        $this->postJson('/api/v1/billing/preview', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.change', true)->assertJsonPath('data.from.plan', 'Starter')
            ->assertJsonPath('data.unused_credit_minor', 1450)->assertJsonPath('data.price_minor', 7900)
            ->assertJsonPath('data.amount_due_minor', 6450)->assertJsonPath('data.lines.0.proration', true);
        $this->assertSame('always_invoice', $this->previewQuery['subscription_proration_behavior'] ?? null);
        $this->assertSame('now', $this->previewQuery['subscription_billing_cycle_anchor'] ?? null);
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'starter'); // a preview changes nothing

        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.updated', true);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions/sub_1') && ($r['items'][0]['price'] ?? null) === 'price_month_7900'
            && $r['proration_behavior'] === 'always_invoice' && $r['billing_cycle_anchor'] === 'now' && $r['payment_behavior'] === 'pending_if_incomplete');
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'growth')->assertJsonPath('data.subscription.interval', 'monthly');

        // Monthly → yearly on the same plan: yearly price selected, charged now, interval and period updated.
        $this->postJson('/api/v1/billing/preview', ['plan' => 'growth', 'interval' => 'yearly'])->assertOk()
            ->assertJsonPath('data.interval', 'yearly')->assertJsonPath('data.price_minor', 79000)->assertJsonPath('data.amount_due_minor', 79000 - 3950);
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'yearly'])->assertOk()->assertJsonPath('data.status', 'active');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions/sub_1') && ($r['items'][0]['price'] ?? null) === 'price_year_79000');
        $billing = $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'growth')->assertJsonPath('data.subscription.interval', 'yearly');
        $this->assertGreaterThan(now()->addMonths(11)->timestamp, strtotime((string) $billing->json('data.subscription.current_period_end')));

        // Yearly → cheaper monthly plan: the unused credit is larger than the new price → nothing to pay, the rest stays as credit.
        $this->postJson('/api/v1/billing/preview', ['plan' => 'starter', 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.amount_due_minor', 0)->assertJsonPath('data.credit_kept_minor', 39500 - 2900);
    }

    public function test_the_plan_only_changes_after_the_payment_has_succeeded(): void
    {
        $this->billingCountry('PK');
        $this->subscribeAndPay('starter');

        // The bank asks for verification: the change waits, the customer stays on Starter.
        $this->changeNeedsAction = true;
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.status', 'requires_confirmation')->assertJsonPath('data.client_secret', 'pi_2_secret');
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'starter');
        $this->postJson('/api/v1/billing/refresh')->assertOk()->assertJsonPath('data.status', 'pending');

        // The customer gives up → the unpaid invoice is withdrawn and nothing changed.
        $this->postJson('/api/v1/billing/refresh', ['abandon' => true])->assertOk()->assertJsonPath('data.status', 'failed');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices/in_2/void'));
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'starter');

        // A declined card is reported with the bank's reason, and again nothing changed.
        $this->changeNeedsAction = false;
        $this->changeDeclined = true;
        $response = $this->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'interval' => 'monthly'])->assertStatus(422);
        $this->assertStringContainsString('insufficient funds', (string) $response->json('error.details.fields.payment_method.0'));
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'starter');
    }

    public function test_payments_are_listed_and_an_ended_subscription_falls_back_to_free_without_a_webhook(): void
    {
        $this->billingCountry('AE');
        $this->subscribeAndPay();

        $this->getJson('/api/v1/billing/payments')->assertOk()->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.status', 'succeeded')->assertJsonPath('data.payments.0.card_last4', '4242')->assertJsonPath('data.payments.0.amount_minor', 8295)
            ->assertJsonPath('data.upcoming.amount_due_minor', 8295);

        // The paid period ended on Stripe but the webhook never arrived: the hourly check moves the workspace to Free.
        $this->remoteSubscription['status'] = 'canceled';
        $this->tenantContext()->run($this->tenant, fn () => Subscription::query()->live()->update(['current_period_end' => now()->subHours(3)]));
        Artisan::call('engage:billing:reconcile');
        $this->actingAsMember($this->owner);
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.subscription.provider', 'manual');
    }

    public function test_receipt_and_first_failed_payment_emails_are_sent_once(): void
    {
        $this->billingCountry('AE', '100200300400003', 'Nova Fitness LLC');
        $this->subscribeAndPay();
        Notification::fake();

        $this->remoteInvoice = [
            'id' => 'in_1', 'number' => 'ENG-0001', 'status' => 'paid', 'currency' => 'usd', 'customer' => 'cus_1',
            'subtotal' => 7900, 'tax' => 395, 'total' => 8295, 'amount_paid' => 8295, 'created' => now()->timestamp,
            'invoice_pdf' => 'https://pay.stripe.com/invoice/1/pdf', 'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => now()->timestamp],
            'lines' => ['data' => [['description' => '1 × 10X Engage Growth']]], 'charge' => ['id' => 'ch_1', 'amount_refunded' => 0],
        ];
        $this->stripeEvent('invoice.paid', ['id' => 'in_1'], 'evt_paid')->assertOk();
        $this->stripeEvent('invoice.paid', ['id' => 'in_1'], 'evt_paid')->assertOk(); // Stripe retry: no second email

        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
        Notification::assertSentOnDemand(PaymentReceiptNotification::class,
            fn (PaymentReceiptNotification $n) => $n->workspace === 'Nova Fitness LLC' && $n->number === 'ENG-0001' && $n->subtotal === 'USD 79.00'
                && $n->tax === 'USD 3.95' && $n->total === 'USD 82.95' && $n->trn === '100200300400003' && $n->pdfUrl === 'https://pay.stripe.com/invoice/1/pdf');

        // A renewal fails: one notice on the first attempt, nothing more on Stripe's later retries.
        $this->remoteInvoice = array_merge($this->remoteInvoice, ['status' => 'open', 'amount_paid' => 0]);
        $this->stripeEvent('invoice.payment_failed', ['id' => 'in_1', 'attempt_count' => 1, 'next_payment_attempt' => now()->addDays(3)->timestamp])->assertOk();
        $this->stripeEvent('invoice.payment_failed', ['id' => 'in_1', 'attempt_count' => 2])->assertOk();
        Notification::assertSentOnDemandTimes(PaymentFailedNotification::class, 1);
    }

    public function test_webhooks_must_be_signed_and_billing_is_owner_only(): void
    {
        $this->stripeEvent('invoice.paid', ['id' => 'in_1'], null, 'whsec_wrong')->assertStatus(400);
        $this->postJson('/api/webhooks/stripe', ['id' => 'evt_x', 'type' => 'invoice.paid'])->assertStatus(400);

        // An event for a customer that is not ours is acknowledged and ignored.
        $this->remoteSubscription = ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_other', 'metadata' => [], 'items' => ['data' => [['id' => 'si', 'price' => ['id' => 'price_unknown']]]]];
        $this->stripeEvent('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro')->assertJsonPath('data.subscription.status', 'trialing');

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Admin));
        $this->getJson('/api/v1/billing')->assertOk();
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'monthly'])->assertForbidden();
        $this->postJson('/api/v1/billing/payment-methods/setup-intent')->assertForbidden();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->getJson('/api/v1/billing')->assertForbidden();

        // Another workspace sees neither the cards nor the invoices.
        $other = $this->createTenant();
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/billing/payment-methods')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/billing/invoices')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_without_stripe_keys_the_page_still_loads_and_buying_explains_why_not(): void
    {
        config(['engage.stripe.secret' => null]);
        $this->billingCountry('AE');

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.stripe_configured', false);
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'monthly'])->assertStatus(503)->assertJsonPath('error.code', 'billing_error');
    }
}
