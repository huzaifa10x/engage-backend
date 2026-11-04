<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Invitation;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Invitation page and acceptance: token, revocation, expiry, email matching, the SPA session flow. */
final class InvitationAcceptTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private function invite(string $email): string
    {
        Notification::fake();
        $tenant = $this->createTenant(['name' => 'Glow Clinic']);
        $this->subscribe($tenant, 'pro');
        $this->actingAsMember($this->addMember($tenant))
            ->postJson('/api/v1/team/invitations', ['email' => $email, 'role_id' => Role::system(SystemRole::Agent)->id])->assertCreated();
        $token = '';
        Notification::assertSentOnDemand(TenantInvitationNotification::class, function (TenantInvitationNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function setInvitation(array $values): void
    {
        $this->tenantContext()->bypass(fn () => DB::table('invitations')->update($values));
    }

    public function test_a_new_account_registered_from_the_link_accepts_in_the_browser_session(): void
    {
        $token = $this->invite('new.agent@example.com');
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/register', [
            'name' => 'New Agent', 'email' => 'New.Agent@example.com', 'password' => 'Secret123456', 'password_confirmation' => 'Secret123456', 'company_name' => 'Own Co',
        ])->assertCreated();

        $this->withHeaders(self::SPA)->getJson("/api/v1/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.workspace', 'Glow Clinic')
            ->assertJsonPath('data.invited_email', 'ne•••@example.com')->assertJsonPath('data.for_you', true);
        $this->withHeaders(self::SPA)->postJson("/api/v1/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.tenant.name', 'Glow Clinic');
    }

    public function test_the_page_shows_the_invitation_before_signing_in(): void
    {
        $token = $this->invite('someone@example.com');

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.role', 'Agent')->assertJsonPath('data.for_you', null)
            ->assertJsonMissingPath('data.email');
        $this->getJson('/api/v1/invitations/'.str_repeat('a', 64))->assertStatus(410)->assertJsonPath('error.details.reason', 'not_found');
    }

    public function test_a_different_signed_in_account_is_told_which_address_was_invited(): void
    {
        $token = $this->invite('invitee@example.com');
        Sanctum::actingAs(User::factory()->create(['email' => 'owner@example.com']));

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.for_you', false);
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410)
            ->assertJsonPath('error.details.reason', 'email_mismatch')
            ->assertJsonPath('error.details.invited_email', 'in•••@example.com');

        // The invitation is still usable by the right person.
        Sanctum::actingAs(User::factory()->create(['email' => 'invitee@example.com']));
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertOk();
    }

    public function test_email_matching_ignores_case_even_for_accounts_stored_with_capitals(): void
    {
        $token = $this->invite('Mixed.Case@Example.com');
        $user = User::factory()->create(['email' => 'mixed.case@example.com']);
        DB::table('users')->where('id', $user->id)->update(['email' => 'Mixed.Case@Example.COM']); // a legacy row
        Sanctum::actingAs($user->refresh());

        $this->postJson("/api/v1/invitations/{$token}/accept")->assertOk();
    }

    public function test_expired_and_revoked_invitations_are_refused_with_their_reason(): void
    {
        $token = $this->invite('late@example.com');
        Sanctum::actingAs(User::factory()->create(['email' => 'late@example.com']));

        $this->setInvitation(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.status', 'expired');
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410)->assertJsonPath('error.details.reason', 'expired');

        $this->setInvitation(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410)->assertJsonPath('error.details.reason', 'revoked');

        $this->tenantContext()->bypass(fn () => $this->assertNull(Invitation::query()->sole()->accepted_at));
    }
}
