<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class PlanManagementTest extends TestCase
{
    /** @return array<string, array<string, mixed>> the active version's features as the editor would send them */
    private function features(Plan $plan): array
    {
        $out = [];
        foreach ($plan->activeVersionOrFail()->features()->with('feature')->get() as $f) {
            $out[(string) $f->feature?->key] = ['enabled' => $f->enabled, 'limit' => $f->limit_value, 'unlimited' => $f->enabled && $f->limit_value === null, 'config' => $f->config];
        }

        return $out;
    }

    /** @param array<string, mixed> $overrides */
    private function form(Plan $plan, array $overrides = []): array
    {
        $version = $plan->activeVersionOrFail();

        return $overrides + [
            'name' => $plan->name, 'description' => $plan->description, 'is_public' => true, 'is_active' => true, 'sort_order' => $plan->sort_order,
            'price_monthly_minor' => $version->price_monthly_minor, 'price_yearly_minor' => $version->price_yearly_minor, 'trial_days' => 0,
            'features' => $this->features($plan), 'apply_to_subscribers' => true,
        ];
    }

    public function test_admins_see_plans_with_prices_and_subscriber_counts(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'growth');
        $this->actingAsAdmin();

        $this->get('/admin/plans')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('plans/Index')
            ->has('plans', 5)
            ->where('plans.2.key', 'growth')->where('plans.2.price_monthly_minor', 7900)->where('plans.2.subscribers', 1)->where('plans.2.is_active', true));

        $growth = Plan::byKey('growth');
        $this->get("/admin/plans/{$growth->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('plans/Edit')
            ->where('plan.key', 'growth')->where('plan.subscribers', 1)
            ->has('features', count(FeatureKey::cases())));
    }

    public function test_editing_a_plan_publishes_a_new_version_and_moves_subscribers_to_the_new_limits(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'starter');
        $owner = $this->addMember($tenant);
        $starter = Plan::byKey('starter');
        $v1 = $starter->activeVersionOrFail();
        $v1->forceFill(['stripe_price_monthly_id' => 'price_m_v1', 'stripe_price_yearly_id' => 'price_y_v1'])->save();

        $features = $this->features($starter);
        $features['tags'] = ['enabled' => true, 'limit' => 60, 'unlimited' => false, 'config' => null];                       // was 25
        $features['api_rate_limit_per_minute'] = ['enabled' => true, 'limit' => 2, 'unlimited' => false, 'config' => null];   // tiny, to test throttling
        $features['broadcasts'] = ['enabled' => false, 'limit' => null, 'unlimited' => false, 'config' => null];              // feature switched off

        $this->actingAsAdmin();
        $this->put("/admin/plans/{$starter->id}", $this->form($starter, ['features' => $features, 'price_yearly_minor' => 30000]))
            ->assertRedirect("/admin/plans/{$starter->id}")->assertSessionHas('success');

        $v2 = $starter->fresh()?->activeVersionOrFail();
        $this->assertSame(2, $v2?->version);
        $this->assertSame('retired', $v1->fresh()?->status->value);
        $this->assertSame('price_m_v1', $v2?->stripe_price_monthly_id); // unchanged price keeps its Stripe price
        $this->assertNull($v2?->stripe_price_yearly_id);                // changed price gets a new one on first sale
        $this->assertSame($v2?->id, $this->tenantContext()->run($tenant, fn () => Subscription::query()->live()->value('plan_version_id')));

        // The subscriber immediately gets the new limits, feature access and rate limit.
        RateLimiter::clear('plan-api:'.$tenant->id);
        $this->app['auth']->forgetGuards();
        $this->actingAsMember($owner);
        $this->getJson('/api/v1/tenant/entitlements')->assertOk()
            ->assertJsonPath('data.features.tags.limit', 60)->assertJsonPath('data.features.broadcasts.enabled', false)
            ->assertJsonPath('data.features.api_rate_limit_per_minute.limit', 2);
        $this->getJson('/api/v1/tenant/entitlements')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->getJson('/api/v1/tenant/entitlements')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_grandfathering_new_plans_and_deactivation(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'starter');
        $starter = Plan::byKey('starter');
        $v1 = $starter->activeVersionOrFail()->id;
        $this->actingAsAdmin();

        // Without "apply to subscribers", existing customers stay on what they bought.
        $this->put("/admin/plans/{$starter->id}", $this->form($starter, ['apply_to_subscribers' => false]))->assertRedirect();
        $this->assertSame($v1, $this->tenantContext()->run($tenant, fn () => Subscription::query()->live()->value('plan_version_id')));

        // A brand-new plan.
        $this->post('/admin/plans', $this->form($starter, ['key' => 'growth_plus', 'name' => 'Growth Plus', 'price_monthly_minor' => 9900, 'price_yearly_minor' => 99000]))->assertRedirect();
        $plus = Plan::byKey('growth_plus');
        $this->assertSame(9900, $plus->activeVersionOrFail()->price_monthly_minor);
        $this->assertSame(count(FeatureKey::cases()), $plus->activeVersionOrFail()->features()->count());
        $this->post('/admin/plans', $this->form($starter, ['key' => 'growth_plus', 'name' => 'Duplicate']))->assertSessionHasErrors('key');

        // Deactivated plans cannot be bought; the fallback plan cannot be deactivated.
        $this->patch("/admin/plans/{$plus->id}/active", ['active' => false])->assertRedirect();
        $this->assertFalse((bool) $plus->fresh()?->is_active);
        $this->patch('/admin/plans/'.Plan::byKey('free')->id.'/active', ['active' => false])->assertSessionHasErrors('active');

        config(['engage.stripe.secret' => 'sk_test_x', 'engage.stripe.key' => 'pk_test_x']);
        $this->app['auth']->forgetGuards();
        $this->actingAsMember($this->addMember($tenant));
        $plans = collect($this->getJson('/api/v1/billing')->assertOk()->json('data.plans'))->keyBy('key');
        $this->assertArrayNotHasKey('growth_plus', $plans->all());
        $this->putJson('/api/v1/billing/details', ['country' => 'PK'])->assertOk();
        $this->postJson('/api/v1/billing/subscribe', ['plan' => 'growth_plus', 'interval' => 'monthly'])->assertStatus(422);
    }

    public function test_only_billing_managers_can_change_plans_or_refund(): void
    {
        $starter = Plan::byKey('starter');

        $this->actingAsAdmin(PlatformRole::Support);
        $this->get('/admin/plans')->assertForbidden();
        $this->put("/admin/plans/{$starter->id}", $this->form($starter))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin(PlatformRole::Finance);
        $this->get('/admin/plans')->assertOk();
        $this->get('/admin/invoices')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('invoices/Index')->has('invoices.data', 0));
    }

    public function test_a_refund_is_sent_to_stripe_and_shown_on_the_invoice(): void
    {
        config(['engage.stripe.secret' => 'sk_test_x']);
        $tenant = $this->createTenant();
        $this->tenantContext()->bypass(fn () => $tenant->forceFill(['stripe_customer_id' => 'cus_1'])->save());
        $invoice = $this->tenantContext()->run($tenant, fn () => Invoice::query()->create([
            'stripe_invoice_id' => 'in_1', 'number' => 'ENG-0001', 'status' => 'paid', 'currency' => 'USD',
            'subtotal_minor' => 7900, 'tax_minor' => 395, 'total_minor' => 8295, 'amount_paid_minor' => 8295, 'issued_at' => now(),
        ]));

        $refunded = 0;
        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/refunds' => function (Request $r) use (&$refunded) {
                $refunded += (int) $r['amount'];

                return Http::response(['id' => 're_1']);
            },
            'api.stripe.com/v1/invoices/in_1*' => function (Request $r) use (&$refunded) {
                return Http::response(['id' => 'in_1', 'number' => 'ENG-0001', 'status' => 'paid', 'currency' => 'usd', 'customer' => 'cus_1', 'subtotal' => 7900, 'tax' => 395,
                    'total' => 8295, 'amount_paid' => 8295, 'created' => now()->timestamp,
                    'charge' => str_contains($r->url(), 'expand') ? ['id' => 'ch_1', 'amount_refunded' => $refunded] : 'ch_1']);
            },
        ]);

        $this->actingAsAdmin();
        $this->post("/admin/invoices/{$invoice->id}/refund", ['amount' => '20.00', 'reason' => 'Goodwill'])->assertRedirect()->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refunds') && $r['charge'] === 'ch_1' && (int) $r['amount'] === 2000);
        $this->assertSame(2000, $this->tenantContext()->bypass(fn () => Invoice::query()->find($invoice->id))?->amount_refunded_minor);

        // More than what is left cannot be refunded.
        $this->post("/admin/invoices/{$invoice->id}/refund", ['amount' => '500.00', 'reason' => 'Too much'])->assertSessionHasErrors('amount');
    }
}
