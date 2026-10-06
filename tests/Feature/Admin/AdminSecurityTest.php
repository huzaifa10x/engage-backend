<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\AdminLoginEvent;
use App\Domain\Identity\Models\AdminSession;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Security\UserAgent;
use App\Domain\Identity\TwoFactor\Totp;
use App\Notifications\AdminTwoFactorCodeNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminSecurityTest extends TestCase
{
    private const PASSWORD = 'Secret-Passw0rd';

    private const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /** The code inside the most recent 2FA email. */
    private function emailedCode(): string
    {
        $code = '';
        Notification::assertSentOnDemand(AdminTwoFactorCodeNotification::class, function (AdminTwoFactorCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function signInWithApp(PlatformAdmin $admin): void
    {
        $this->withHeaders(['User-Agent' => self::CHROME_MAC, 'CF-IPCountry' => 'AE'])
            ->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin/two-factor/challenge');
        $this->post('/admin/two-factor/challenge', ['code' => Totp::codeAt((string) $admin->two_factor_secret, Totp::currentStep())])->assertRedirect('/admin');

        // Like a real browser: keep sending the session cookie, so the session id stays the same between requests.
        $this->withCookie((string) config('session.cookie'), $this->app['session']->getId());
    }

    private function signOut(): void
    {
        $this->post('/admin/logout');
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = []; // the browser's session cookie is gone
    }

    public function test_a_login_is_recorded_with_device_browser_and_ip_and_listed_as_an_active_session(): void
    {
        $admin = $this->createAdmin();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->signInWithApp($admin);

        $this->get('/admin/security')->assertOk()->assertInertia(fn (Assert $page) => $page->component('security/Index')
            ->where('twoFactor.enabled', true)->where('twoFactor.method', 'app')->where('twoFactor.required', true)
            ->has('sessions', 1)
            ->where('sessions.0.current', true)->where('sessions.0.browser', 'Chrome 126')->where('sessions.0.os', 'macOS')
            ->where('sessions.0.device', 'Desktop')->where('sessions.0.ip', '127.0.0.1')->where('sessions.0.country', 'AE')
            ->has('history', 2)
            ->where('history.0.event', 'login')->where('history.0.method', 'app')->where('history.0.admin', $admin->name)
            ->where('history.1.event', 'failed_password'));

        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertSame(0, AdminSession::query()->whereNull('revoked_at')->count());
        $this->assertSame('logout', AdminLoginEvent::query()->orderByDesc('created_at')->orderByDesc('id')->value('event'));
    }

    public function test_switching_from_the_app_to_email_codes_and_back(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $this->signInWithApp($admin);

        // The password is asked again; a wrong one changes nothing.
        $this->post('/admin/security/two-factor/email', ['password' => 'nope'])->assertSessionHasErrors('password');
        Notification::assertNothingSent();

        // Email 2FA is only switched on after a code that really arrived has been entered.
        $this->post('/admin/security/two-factor/email', ['password' => self::PASSWORD])->assertRedirect();
        $this->assertSame('app', $admin->fresh()?->twoFactorMethod());
        $this->post('/admin/security/two-factor/email/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/admin/security/two-factor/email/confirm', ['code' => $this->emailedCode()])->assertRedirect();
        $this->assertSame('email', $admin->fresh()?->twoFactorMethod());
        $this->assertNull($admin->fresh()?->two_factor_secret);

        // Next sign-in: a code is emailed; a wrong code is refused, the right one works once.
        $this->signOut();
        Notification::fake();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin/two-factor/challenge');
        $this->get('/admin/two-factor/challenge')->assertInertia(fn (Assert $page) => $page->where('method', 'email'));
        $code = $this->emailedCode();
        $this->post('/admin/two-factor/challenge', ['code' => $code === '111111' ? '222222' : '111111'])->assertSessionHasErrors('code');
        $this->post('/admin/two-factor/challenge', ['code' => $code])->assertRedirect('/admin');
        $this->assertSame('email', AdminLoginEvent::query()->where('event', 'login')->orderByDesc('created_at')->orderByDesc('id')->value('method'));

        // And back to an authenticator app.
        $this->post('/admin/security/two-factor/app', ['password' => self::PASSWORD])->assertRedirect();
        $secret = str_replace(' ', '', (string) $this->get('/admin/security')->viewData('page')['props']['appSetup']['secret']);
        $this->post('/admin/security/two-factor/app/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/admin/security/two-factor/app/confirm', ['code' => Totp::codeAt($secret, Totp::currentStep())])->assertRedirect('/admin/two-factor/recovery-codes');
        $this->assertSame('app', $admin->fresh()?->twoFactorMethod());
        $this->assertSame($secret, $admin->fresh()?->two_factor_secret);
    }

    public function test_two_factor_can_only_be_turned_off_when_the_platform_allows_it(): void
    {
        $admin = $this->createAdmin();
        $this->signInWithApp($admin);

        $this->delete('/admin/security/two-factor', ['password' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->assertTrue((bool) $admin->fresh()?->hasTwoFactor());

        config(['engage.admin.two_factor_required' => false]);
        $this->delete('/admin/security/two-factor', ['password' => 'nope'])->assertSessionHasErrors('password');
        $this->delete('/admin/security/two-factor', ['password' => self::PASSWORD])->assertRedirect();
        $this->assertFalse((bool) $admin->fresh()?->hasTwoFactor());

        // Without 2FA (and not required) the password alone signs in, and that is what the history says.
        $this->signOut();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin');
        $this->assertSame('none', AdminLoginEvent::query()->where('event', 'login')->orderByDesc('created_at')->orderByDesc('id')->value('method'));

        // Required again → the next login forces enrolment.
        config(['engage.admin.two_factor_required' => true]);
        $this->signOut();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin/two-factor/setup');
    }

    public function test_first_login_can_enrol_with_email_codes_instead_of_an_app(): void
    {
        Notification::fake();
        $admin = $this->createAdmin(withTwoFactor: false);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin/two-factor/setup');
        $this->post('/admin/two-factor/setup/email')->assertRedirect();
        $this->get('/admin/two-factor/setup')->assertInertia(fn (Assert $page) => $page->where('email_pending', true));
        $this->post('/admin/two-factor/setup/email/confirm', ['code' => $this->emailedCode()])->assertRedirect('/admin/two-factor/recovery-codes');

        $this->assertSame('email', $admin->fresh()?->twoFactorMethod());
        $this->get('/admin/two-factor/recovery-codes')->assertInertia(fn (Assert $page) => $page->has('codes', 8));
        $this->get('/admin/security')->assertOk();
    }

    public function test_a_session_signed_out_remotely_is_logged_out_on_its_next_request(): void
    {
        $admin = $this->createAdmin();
        $this->signInWithApp($admin);
        $this->get('/admin/security')->assertOk();

        // A second browser of the same admin.
        $other = AdminSession::query()->create(['platform_admin_id' => $admin->id, 'session_hash' => hash('sha256', 'other-browser'), 'ip' => '203.0.113.9',
            'browser' => 'Safari 17', 'os' => 'iOS', 'device' => 'Mobile', 'last_active_at' => now()->subMinutes(5)]);
        $this->get('/admin/security')->assertInertia(fn (Assert $page) => $page->has('sessions', 2));

        $current = AdminSession::query()->where('id', '!=', $other->id)->firstOrFail();
        $this->delete("/admin/security/sessions/{$current->id}")->assertSessionHas('error'); // not the one in use
        $this->delete("/admin/security/sessions/{$other->id}")->assertSessionHas('success');
        $this->assertNotNull($other->fresh()?->revoked_at);
        $this->assertSame('session_revoked', AdminLoginEvent::query()->orderByDesc('created_at')->orderByDesc('id')->value('event'));

        // The same thing seen from the revoked browser: its next request lands on the login page.
        $current->forceFill(['revoked_at' => now()])->save();
        $this->get('/admin/security')->assertRedirect('/admin/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_only_super_admins_see_other_admins_history_and_sessions(): void
    {
        $owner = $this->createAdmin();
        $support = $this->createAdmin(PlatformRole::Support);
        $this->signInWithApp($support);

        $this->get('/admin/security')->assertInertia(fn (Assert $page) => $page->where('seesEveryone', false)->has('history', 1)->has('sessions', 1));
        $foreign = AdminSession::query()->create(['platform_admin_id' => $owner->id, 'session_hash' => hash('sha256', 'owner'), 'last_active_at' => now()]);
        $this->delete("/admin/security/sessions/{$foreign->id}")->assertForbidden();

        $this->signOut();
        $this->signInWithApp($owner);
        $this->get('/admin/security')->assertInertia(fn (Assert $page) => $page->where('seesEveryone', true)
            ->has('history', 3)                        // support login + logout, owner login
            ->where('history.0.admin', $owner->name)->where('history.2.admin', $support->name));

        $this->post('/admin/security/test-email')->assertSessionHas('success');
    }

    public function test_user_agents_are_read_into_browser_os_and_device(): void
    {
        $this->assertSame(['browser' => 'Chrome 126', 'os' => 'macOS', 'device' => 'Desktop'], UserAgent::parse(self::CHROME_MAC));
        $this->assertSame(['browser' => 'Safari 17', 'os' => 'iOS', 'device' => 'Mobile'],
            UserAgent::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'));
        $this->assertSame(['browser' => 'Edge 125', 'os' => 'Windows 10/11', 'device' => 'Desktop'],
            UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0'));
        $this->assertSame(['browser' => 'Firefox 127', 'os' => 'Android', 'device' => 'Mobile'], UserAgent::parse('Mozilla/5.0 (Android 14; Mobile; rv:127.0) Gecko/127.0 Firefox/127.0'));
        $this->assertSame(['browser' => 'Unknown', 'os' => 'Unknown', 'device' => 'Desktop'], UserAgent::parse(null));
    }
}
