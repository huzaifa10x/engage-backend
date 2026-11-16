<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Conversions to Meta, 3b: the App Review workspace and its reviewer login. */
final class ReviewerWorkspaceTest extends TestCase
{
    public function test_it_creates_one_workspace_with_one_review_login_and_shows_the_checklist(): void
    {
        $other = $this->createTenant(['name' => 'Team Dubai']);

        $this->assertSame(0, Artisan::call('engage:conversions:reviewer', ['--create' => true, '--email' => 'App.Review@10xdigital.ae']));
        $output = Artisan::output();
        $this->assertStringContainsString('Created "engageX App Review" with one login.', $output);
        $this->assertStringContainsString('Test number connected', $output);
        $this->assertSame(1, preg_match('/Password: (\S+)/', $output, $m));
        $password = $m[1];

        $tenant = $this->tenantContext()->bypass(fn () => Tenant::query()->where('name', 'engageX App Review')->firstOrFail());
        $user = $this->tenantContext()->bypass(fn () => User::query()->where('email', 'app.review@10xdigital.ae')->firstOrFail());
        $this->assertTrue($user->isReviewAccount());
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => $user->memberships()->count()));
        $this->assertTrue(ConversionsAccess::allowed($tenant));
        $this->assertTrue(GrowthSettings::for($tenant)->enabled);
        $this->assertSame(SubscriptionStatus::Active, $this->tenantContext()->bypass(fn () => $tenant->liveSubscription()->first()?->status));

        // Nothing else is touched.
        $this->assertFalse(ConversionsAccess::allowed($this->tenantContext()->bypass(fn () => $other->refresh())));

        // The reviewer signs in with email and password only, straight into the workspace.
        $spa = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
        $this->withHeaders($spa)->postJson('/api/v1/auth/login', ['email' => 'app.review@10xdigital.ae', 'password' => $password])->assertOk()
            ->assertJsonPath('data.two_factor.required', false)->assertJsonPath('data.two_factor.review_account', true);
        $this->withHeaders($spa)->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.name', 'engageX App Review');

        // Run again: no second workspace; the checklist shows what is missing.
        $this->artisan('engage:conversions:reviewer', ['--create' => true, '--email' => 'other@10xdigital.ae'])->assertFailed();
        $this->artisan('engage:conversions:reviewer')
            ->expectsOutputToContain('Reviewer workspace: engageX App Review')
            ->expectsOutputToContain('item(s) still to do.')
            ->assertSuccessful();
    }

    public function test_an_address_that_already_has_a_login_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@10xdigital.ae']);
        $this->artisan('engage:conversions:reviewer', ['--create' => true, '--email' => 'taken@10xdigital.ae'])
            ->expectsOutputToContain('already has a login')->assertFailed();
        $this->artisan('engage:conversions:reviewer', ['--create' => true])->assertFailed();
    }
}
