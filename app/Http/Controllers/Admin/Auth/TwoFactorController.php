<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\TwoFactor\Totp;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Step 2 of 2: TOTP challenge, or mandatory enrolment on first login. */
final class TwoFactorController extends Controller
{
    private const SETUP_SECRET = 'admin.2fa.setup_secret';

    private const RECOVERY_CODES = 'admin.2fa.recovery_codes';

    private const PENDING_TTL = 600;

    public function __construct(private readonly AuditLogger $audit) {}

    public function challenge(Request $request): Response|RedirectResponse
    {
        $admin = $this->pending($request);
        if ($admin === null) {
            return redirect()->route('admin.login');
        }
        if (! $admin->hasTwoFactor()) {
            return redirect()->route('admin.two-factor.setup');
        }

        return Inertia::render('auth/TwoFactorChallenge', ['email' => $admin->email]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $admin = $this->pending($request);
        if ($admin === null || ! $admin->hasTwoFactor()) {
            return redirect()->route('admin.login');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $key = 'admin-2fa:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        if (filled($data['recovery_code'] ?? null)) {
            $codes = $admin->two_factor_recovery_codes ?? [];
            $given = mb_strtolower(trim((string) $data['recovery_code']));
            $match = collect($codes)->first(fn (string $c) => hash_equals($c, $given));

            if ($match === null) {
                RateLimiter::hit($key, 60);
                throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not valid.']);
            }

            $admin->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$match]))])->save();
            $this->audit->record('admin.recovery_code_used', $admin, meta: ['remaining' => count($codes) - 1]);
        } else {
            $step = Totp::verify((string) $admin->two_factor_secret, (string) ($data['code'] ?? ''), $admin->two_factor_last_used_step);

            if ($step === null) {
                RateLimiter::hit($key, 60);
                throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
            }

            $admin->forceFill(['two_factor_last_used_step' => $step])->save();
        }

        RateLimiter::clear($key);

        return $this->completeLogin($request, $admin);
    }

    public function setup(Request $request): Response|RedirectResponse
    {
        $admin = $this->pending($request);
        if ($admin === null) {
            return redirect()->route('admin.login');
        }
        if ($admin->hasTwoFactor()) {
            return redirect()->route('admin.two-factor.challenge');
        }

        $secret = $request->session()->get(self::SETUP_SECRET);
        if (! is_string($secret)) {
            $secret = Totp::generateSecret();
            $request->session()->put(self::SETUP_SECRET, $secret);
        }

        return Inertia::render('auth/TwoFactorSetup', [
            'email' => $admin->email,
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'otpauth_uri' => Totp::provisioningUri($secret, $admin->email, '10X Engage Admin'),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $admin = $this->pending($request);
        $secret = $request->session()->get(self::SETUP_SECRET);

        if ($admin === null || ! is_string($secret)) {
            return redirect()->route('admin.login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $step = Totp::verify($secret, $data['code']);

        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Scan the QR code again and enter the current 6-digit code.']);
        }

        $codes = Totp::recoveryCodes();
        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => $step,
            'two_factor_recovery_codes' => $codes,
        ])->save();

        $request->session()->forget(self::SETUP_SECRET);
        $this->completeLogin($request, $admin);
        $this->audit->record('admin.two_factor_enabled', $admin);

        $request->session()->put(self::RECOVERY_CODES, $codes);

        return redirect()->route('admin.two-factor.recovery-codes');
    }

    /** Shown exactly once, right after enrolment. */
    public function recoveryCodes(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->pull(self::RECOVERY_CODES);

        return is_array($codes)
            ? Inertia::render('auth/RecoveryCodes', ['codes' => $codes])
            : redirect()->route('admin.overview');
    }

    private function completeLogin(Request $request, PlatformAdmin $admin): RedirectResponse
    {
        $remember = (bool) ($request->session()->get(LoginController::PENDING)['remember'] ?? false);

        Auth::guard('admin')->login($admin, $remember);
        $request->session()->forget(LoginController::PENDING);
        $request->session()->regenerate();

        $admin->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('admin.login', $admin);

        return redirect()->intended(route('admin.overview'));
    }

    private function pending(Request $request): ?PlatformAdmin
    {
        $pending = $request->session()->get(LoginController::PENDING);

        if (! is_array($pending) || (time() - (int) ($pending['at'] ?? 0)) > self::PENDING_TTL) {
            return null;
        }

        $admin = PlatformAdmin::query()->find($pending['id'] ?? null);

        return $admin instanceof PlatformAdmin && $admin->isActive() ? $admin : null;
    }
}
