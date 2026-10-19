<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Billing\SubscriptionService;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Authentication templates, filtered contact export, and "Switch to Free". */
final class WorkspaceRequestsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const LIST = 'graph.facebook.com/v26.0/102290129340398/message_templates*';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);
    }

    public function test_an_authentication_template_is_submitted_in_metas_fixed_format(): void
    {
        // Meta writes the text itself; the list read afterwards brings it in.
        Http::fake([self::LIST => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['id' => '777100', 'status' => 'PENDING', 'category' => 'AUTHENTICATION'])
            : Http::response(['data' => [[
                'id' => '777100', 'name' => 'login_code', 'language' => 'en_US', 'status' => 'PENDING', 'category' => 'AUTHENTICATION',
                'components' => [
                    ['type' => 'BODY', 'text' => '*{{1}}* is your verification code. For your security, do not share this code.'],
                    ['type' => 'FOOTER', 'text' => 'This code expires in 10 minutes.'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Copy code', 'url' => 'https://www.whatsapp.com/otp/code/?otp_type=COPY_CODE&code=otp{{1}}']]],
                ],
            ]]])]);

        $this->postJson('/api/v1/templates', [
            'waba_account_id' => $this->number->waba_account_id, 'name' => 'login_code', 'language' => 'en_US', 'category' => 'AUTHENTICATION',
            'add_security_recommendation' => true, 'code_expiration_minutes' => 10, 'otp_button_text' => 'Copy code',
        ])->assertCreated()->assertJsonPath('data.category', 'AUTHENTICATION');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/message_templates')
            && $r['category'] === 'AUTHENTICATION'
            && $r['components'] === [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code']]],
            ]);

        // The real text is read back from Meta straight away.
        $this->getJson('/api/v1/templates')->assertJsonPath('data.0.name', 'login_code')
            ->assertJsonPath('data.0.components.0.text', '*{{1}}* is your verification code. For your security, do not share this code.');

        // Expiry outside Meta's 1–90 minutes is refused before calling Meta.
        $this->postJson('/api/v1/templates', [
            'waba_account_id' => $this->number->waba_account_id, 'name' => 'login_code_two', 'language' => 'en_US', 'category' => 'AUTHENTICATION',
            'code_expiration_minutes' => 120,
        ])->assertStatus(422);
    }

    public function test_the_contact_export_can_be_narrowed_and_counted_first(): void
    {
        foreach ([['+971501110001', 'Sara', ['VIP'], true], ['+971501110002', 'Omar', ['VIP'], false], ['+971501110003', 'Lina', [], true]] as [$phone, $name, $tags, $in]) {
            $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => $name, 'tags' => $tags, 'opted_in' => $in])->assertCreated();
        }
        // Lina was added long ago.
        $this->tenantContext()->bypass(fn () => Contact::query()->where('name', 'Lina')->update(['created_at' => now()->subMonths(3)]));

        $this->getJson('/api/v1/contacts/export/count')->assertOk()->assertJsonPath('data.count', 3);
        $this->getJson('/api/v1/contacts/export/count?tag=VIP')->assertJsonPath('data.count', 2);
        $this->getJson('/api/v1/contacts/export/count?tag=VIP&consent=opted_in')->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/contacts/export/count?q=oma')->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/contacts/export/count?added_from='.now()->subWeek()->toDateString())->assertJsonPath('data.count', 2);
        $this->getJson('/api/v1/contacts/export/count?added_from=2026-10-10&added_to=2026-10-01')->assertStatus(422);

        $csv = $this->get('/api/v1/contacts/export?tag=VIP&consent=opted_in')->assertOk()->streamedContent();
        $this->assertStringContainsString('+971501110001', $csv);
        $this->assertStringNotContainsString('+971501110002', $csv);
        $this->assertStringNotContainsString('+971501110003', $csv);
    }

    public function test_a_trial_switches_to_free_straight_away(): void
    {
        app(SubscriptionService::class)->startTrial($this->tenant);

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.plans.0.free', true)->assertJsonPath('data.plans.0.current', false);

        $this->postJson('/api/v1/billing/switch-to-free')->assertOk()
            ->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.plans.0.current', true);
    }

    public function test_a_plan_set_up_by_the_platform_is_not_switched_by_the_workspace(): void
    {
        // setUp assigned Pro manually (no Stripe, not a trial).
        $this->postJson('/api/v1/billing/switch-to-free')->assertStatus(422);
        $this->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'pro');
    }
}
