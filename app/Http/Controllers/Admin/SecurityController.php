<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\AdminLoginEvent;
use App\Domain\Identity\Models\AdminSession;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Security\AdminSecurity;
use App\Domain\Identity\TwoFactor\Totp;
use App\Http\Controllers\Admin\Auth\TwoFactorController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Super Admin → Security: two-factor method (authenticator app ⇄ email, or off when the platform
 * allows it), active sessions with remote sign-out, and the login history. Every change to 2FA
 * asks for the admin's password again and is written to the audit log.
 */
final class SecurityController extends Controller
{
    private const APP_SECRET = 'admin.security.app_secret';

    private const EMAIL_PENDING = 'admin.security.email_pending';

    public function __construct(private readonly AdminSecurity $security, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $admin = $this->admin($request);
        $current = hash('sha256', $request->session()->getId());
        $lifetime = now()->subMinutes((int) config('session.lifetime', 120));
        $secret = $request->session()->get(self::APP_SECRET);

        $sessions = AdminSession::query()->whereNull('revoked_at')->where('last_active_at', '>=', $lifetime)
            ->when(! $admin->isSuperAdmin(), fn ($q) => $q->where('platform_admin_id', $admin->id))
            ->with('admin:id,name,email')->orderByDesc('last_active_at')->limit(100)->get();

        $history = AdminLoginEvent::query()
            ->when(! $admin->isSuperAdmin(), fn ($q) => $q->where('platform_admin_id', $admin->id))
            ->with('admin:id,name,email')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return Inertia::render('security/Index', [
            'twoFactor' => [
                'enabled' => $admin->hasTwoFactor(),
                'method' => $admin->twoFactorMethod(),
                'required' => AdminSecurity::twoFactorRequired(),
                'recovery_codes_left' => count($admin->two_factor_recovery_codes ?? []),
                'email' => $admin->email,
            ],
            'appSetup' => is_string($secret) ? [
                'secret' => trim(chunk_split($secret, 4, ' ')),
                'otpauth_uri' => Totp::provisioningUri($secret, $admin->email, '10X Engage Admin'),
            ] : null,
            'emailPending' => $request->session()->get(self::EMAIL_PENDING) === true,
            'seesEveryone' => $admin->isSuperAdmin(),
            'sessions' => $sessions->map(fn (AdminSession $s) => [
                'id' => $s->id,
                'current' => $s->session_hash === $current,
                'admin' => $s->admin?->name,
                'admin_email' => $s->admin?->email,
                'mine' => $s->platform_admin_id === $admin->id,
                'ip' => $s->ip,
                'country' => $s->country,
                'browser' => $s->browser,
                'os' => $s->os,
                'device' => $s->device,
                'last_active_at' => $s->last_active_at->toIso8601String(),
                'signed_in_at' => $s->created_at?->toIso8601String(),
            ]),
            'history' => $history->map(fn (AdminLoginEvent $e) => [
                'id' => $e->id,
                'event' => $e->event,
                'method' => $e->method,
                'admin' => $e->admin?->name,
                'email' => $e->email,
                'ip' => $e->ip,
                'country' => $e->country,
                'browser' => $e->browser,
                'os' => $e->os,
                'device' => $e->device,
                'at' => $e->created_at?->toIso8601String(),
            ]),
            'mail' => ['mailer' => (string) config('mail.default'), 'from' => (string) config('mail.from.address')],
        ]);
    }

    // ── Authenticator app ──────────────────────────────────────────────────────────────────

    public function startApp(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);
        $request->session()->put(self::APP_SECRET, Totp::generateSecret());
        $request->session()->forget(self::EMAIL_PENDING);

        return back();
    }

    public function confirmApp(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $secret = $request->session()->get(self::APP_SECRET);
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $step = is_string($secret) ? Totp::verify($secret, $data['code']) : null;

        if (! is_string($secret) || $step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Enter the current 6-digit code from your authenticator app.']);
        }

        $codes = Totp::recoveryCodes();
        $from = $admin->twoFactorMethod();
        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_method' => 'app',
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => $step,
            'two_factor_recovery_codes' => $codes,
        ])->save();
        $request->session()->forget(self::APP_SECRET);
        $this->audit->record('admin.two_factor_method_changed', $admin, meta: ['from' => $from ?? 'off', 'to' => 'app']);
        $request->session()->put(TwoFactorController::RECOVERY_CODES, $codes);

        return redirect()->route('admin.two-factor.recovery-codes');
    }

    public function cancelSetup(Request $request): RedirectResponse
    {
        $request->session()->forget([self::APP_SECRET, self::EMAIL_PENDING]);

        return back();
    }

    // ── Email codes ────────────────────────────────────────────────────────────────────────

    /** Sends a code first: email 2FA is only switched on once a code has actually arrived. */
    public function startEmail(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $this->confirmPassword($request);

        try {
            $this->security->sendEmailCode($admin, 'switch to email verification');
        } catch (Throwable $e) {
            Log::error('Admin 2FA email could not be sent', ['admin' => $admin->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['password' => 'The email could not be sent, so email verification was not switched on. Check the mail settings (use "Send test email").']);
        }
        $request->session()->put(self::EMAIL_PENDING, true);
        $request->session()->forget(self::APP_SECRET);

        return back()->with('success', "We sent a 6-digit code to {$admin->email}.");
    }

    public function confirmEmail(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        if ($request->session()->get(self::EMAIL_PENDING) !== true || ! $this->security->verifyEmailCode($admin, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not valid or has expired. Start again to get a new code.']);
        }

        $from = $admin->twoFactorMethod();
        $codes = $admin->two_factor_recovery_codes ?: Totp::recoveryCodes();
        $admin->forceFill(['two_factor_method' => 'email', 'two_factor_secret' => null, 'two_factor_last_used_step' => null, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        $request->session()->forget(self::EMAIL_PENDING);
        $this->audit->record('admin.two_factor_method_changed', $admin, meta: ['from' => $from ?? 'off', 'to' => 'email']);

        if ($from === null) {
            $request->session()->put(TwoFactorController::RECOVERY_CODES, $codes);

            return redirect()->route('admin.two-factor.recovery-codes');
        }

        return back()->with('success', 'Two-factor authentication now uses email codes.');
    }

    // ── Off / recovery codes ───────────────────────────────────────────────────────────────

    public function disable(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        if (AdminSecurity::twoFactorRequired()) {
            throw ValidationException::withMessages(['password' => 'Two-factor authentication is required on this platform and cannot be switched off.']);
        }
        $this->confirmPassword($request);

        $from = $admin->twoFactorMethod();
        $admin->forceFill(['two_factor_method' => null, 'two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_used_step' => null, 'two_factor_recovery_codes' => null])->save();
        $request->session()->forget([self::APP_SECRET, self::EMAIL_PENDING]);
        $this->audit->record('admin.two_factor_disabled', $admin, meta: ['from' => $from ?? 'off']);

        return back()->with('success', 'Two-factor authentication is off. Your account is now protected by your password only.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        if (! $admin->hasTwoFactor()) {
            return back();
        }
        $this->confirmPassword($request);

        $codes = Totp::recoveryCodes();
        $admin->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $this->audit->record('admin.recovery_codes_regenerated', $admin);
        $request->session()->put(TwoFactorController::RECOVERY_CODES, $codes);

        return redirect()->route('admin.two-factor.recovery-codes');
    }

    // ── Sessions ───────────────────────────────────────────────────────────────────────────

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        $admin = $this->admin($request);
        $target = AdminSession::query()->whereNull('revoked_at')->findOrFail($session);
        abort_unless($target->platform_admin_id === $admin->id || $admin->isSuperAdmin(), 403);

        if ($target->session_hash === hash('sha256', $request->session()->getId())) {
            return back()->with('error', 'This is the session you are using. Use Sign out instead.');
        }

        $target->forceFill(['revoked_at' => now()])->save();
        $owner = PlatformAdmin::query()->find($target->platform_admin_id);
        $this->security->record($request, 'session_revoked', $owner);
        $this->audit->record('admin.session_revoked', $owner ?? $admin, meta: ['by' => $admin->email, 'ip' => $target->ip, 'browser' => $target->browser]);

        return back()->with('success', 'That session has been signed out.');
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $count = AdminSession::query()->where('platform_admin_id', $admin->id)->whereNull('revoked_at')
            ->where('session_hash', '!=', hash('sha256', $request->session()->getId()))->update(['revoked_at' => now()]);
        $this->audit->record('admin.other_sessions_revoked', $admin, meta: ['count' => $count]);

        return back()->with('success', $count > 0 ? "Signed out of {$count} other session(s)." : 'There were no other sessions.');
    }

    // ── Mail ───────────────────────────────────────────────────────────────────────────────

    /** Proves the SMTP settings work by sending a real message to the admin's own address. */
    public function testEmail(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $key = 'admin-test-mail:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('error', 'Please wait a minute before sending another test email.');
        }
        RateLimiter::hit($key, 60);

        try {
            $html = (string) (new MailMessage)->subject('10X Engage: test email')->greeting('It works')
                ->line('This is a test message from the 10X Engage admin panel. Your email settings are working.')->render();
            Mail::html($html, fn ($message) => $message->to($admin->email)->subject('10X Engage: test email'));
        } catch (Throwable $e) {
            Log::error('Admin test email failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'The test email could not be sent: '.mb_substr($e->getMessage(), 0, 220));
        }

        return back()->with('success', "Test email sent to {$admin->email}. Check the inbox (and spam folder).");
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────

    private function admin(Request $request): PlatformAdmin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        return $admin;
    }

    /** Sensitive changes need the password again, even inside a signed-in session. */
    private function confirmPassword(Request $request): void
    {
        $admin = $this->admin($request);
        $data = $request->validate(['password' => ['required', 'string']]);
        $key = 'admin-confirm:'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Hash::check($data['password'], $admin->getAuthPassword())) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }
        RateLimiter::clear($key);
    }
}
