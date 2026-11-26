<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\EmailVerification;
use App\Application\Identity\LoginVerification;
use App\Application\Tenancy\RegisterWorkspace;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Notifications\TwoFactorChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Throwable;

/**
 * Sanctum SPA authentication (session cookie). The Next.js client first calls
 * GET /sanctum/csrf-cookie, then these endpoints with credentials + X-XSRF-TOKEN.
 */
final class AuthController extends Controller
{
    private const PENDING = 'auth.pending_login';

    public function register(RegisterRequest $request, RegisterWorkspace $register, MeController $me, EmailVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);

        $result = $register($request->validated());
        // The account exists but cannot be used until the emailed code is entered (EnsureEmailVerified).
        $verification->send($result['user']);

        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();
        $request->session()->put((string) config('engage.tenancy.session_key'), $result['tenant']->getKey());
        $request->session()->put(LoginVerification::SESSION_STAMP, now()->timestamp);

        return $me->payload($result['user'], (string) $result['tenant']->getKey())->setStatusCode(201);
    }

    /**
     * Step 1: email + password. A browser trusted within the last 24 hours is signed in directly;
     * any other gets a one-time code by email and must call loginVerify() to finish.
     */
    public function login(LoginRequest $request, MeController $me, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);

        $user = $request->authenticate();
        $remember = $request->boolean('remember');

        // A review account (Meta App Review) is never asked for a second step; every sign-in is audited.
        if ($user->isReviewAccount()) {
            $request->session()->regenerate();
            $request->session()->put(LoginVerification::SESSION_STAMP, now()->timestamp);
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
            app(AuditLogger::class)->record('user.review_account_signed_in', $user);

            return $me->payload($user, $user->last_active_tenant_id);
        }

        // Two-factor sign-in (authenticator app) replaces the emailed code: always asked, on every browser.
        if ($user->hasTwoFactor()) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->put(self::PENDING, ['id' => $user->id, 'remember' => $remember, 'at' => now()->timestamp, 'two_factor' => true]);

            return response()->json(['data' => ['two_factor_required' => true]], 202);
        }
        $trusted = LoginVerification::enabled() ? $verification->trusted($request, $user) : ['at' => now()->timestamp, 'until' => null];

        if ($trusted === null) {
            // Password correct, but not signed in yet: park the sign-in until the code is entered.
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->put(self::PENDING, [
                'id' => $user->id, 'remember' => $remember, 'remember_device' => $request->boolean('remember_device'), 'at' => now()->timestamp,
            ]);

            return $this->sendLoginCode($user, $verification);
        }

        // The installed app keeps its session through idle time (its 90 days are checked on every request).
        if (($trusted['device'] ?? null) !== null) {
            Auth::guard('web')->login($user, true);
        }
        $request->session()->regenerate();
        LoginVerification::stamp($request, $trusted['at'], $trusted['until'], $trusted['device'] ?? null);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $me->payload($user, $user->last_active_tenant_id);
    }

    /**
     * Sign in with a code instead of a password (mobile brief): the code is emailed to the address
     * if it belongs to an account. The answer is the same either way, so it never tells anyone
     * whether an address has an account. Two-factor sign-in still asks for the authenticator code.
     */
    public function loginCode(Request $request, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:190'], 'remember_device' => ['sometimes', 'boolean']]);
        $email = mb_strtolower(trim($data['email']));
        foreach (['login-code-ip:'.$request->ip() => 20, 'login-code-email:'.sha1($email) => 6] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => 'Too many codes requested. Try again in '.(int) ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
            }
            RateLimiter::hit($key, 900);
        }

        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $pending = ['remember' => true, 'remember_device' => $request->boolean('remember_device'), 'at' => now()->timestamp, 'passwordless' => true];
        if (! $user instanceof User || $user->isDisabled() || $user->isReviewAccount()) {
            // No account: the same answer as for one, and no code exists to match.
            $request->session()->put(self::PENDING, $pending + ['id' => null]);
            [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

            return response()->json(['data' => [
                'otp_required' => true,
                'email' => mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain,
                'retry_in' => 60,
                'expires_in' => LoginVerification::CODE_MINUTES * 60,
            ]], 202);
        }
        $request->session()->put(self::PENDING, $pending + ['id' => $user->id]);

        return $this->sendLoginCode($user, $verification);
    }

    /**
     * Step 2: the emailed code. Signs the user in and trusts this browser for 24 hours, or for
     * 30 days when "Remember this device" was ticked (at step 1, or sent again with the code).
     */
    public function loginVerify(Request $request, MeController $me, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);
        $user = $this->pendingUser($request);
        $data = $request->validate(
            ['code' => ['required', 'string', 'regex:/^\s*\d[\d\s-]{4,10}\d\s*$/'], 'remember_device' => ['sometimes', 'boolean']],
            ['code.regex' => 'Enter the 6-digit code from the email.'],
        );

        foreach (['login-otp-verify:'.$user->id => 10, 'login-otp-verify-ip:'.$request->ip() => 30] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.(int) ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
            }
            RateLimiter::hit($key, 900);
        }

        $result = $verification->check($user, $data['code']);
        if ($result !== 'ok') {
            throw ValidationException::withMessages(['code' => match ($result) {
                'expired' => 'This code has expired. Request a new one.',
                'locked' => 'Too many wrong codes. Request a new code.',
                default => 'That code is not correct. Check the latest email and try again.',
            }]);
        }
        RateLimiter::clear('login-otp-verify:'.$user->id);

        $pending = (array) $request->session()->pull(self::PENDING);
        // Signed in with a code alone and two-factor sign-in is on: the authenticator code comes next.
        if (($pending['passwordless'] ?? false) && $user->hasTwoFactor()) {
            $request->session()->put(self::PENDING, ['id' => $user->id, 'remember' => true, 'at' => now()->timestamp, 'two_factor' => true]);

            return response()->json(['data' => ['two_factor_required' => true]], 202);
        }
        $rememberDevice = $request->has('remember_device') ? $request->boolean('remember_device') : (bool) ($pending['remember_device'] ?? false);
        $device = LoginVerification::appDevice($request);
        Auth::guard('web')->login($user, $device !== null || (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();
        $cookie = $verification->trust($user, $rememberDevice, $device);
        LoginVerification::stamp($request, now()->timestamp, LoginVerification::validUntil($rememberDevice, $device), $device);
        // A code read from the inbox also proves the address belongs to them.
        $user->forceFill(['last_login_at' => now(), 'email_verified_at' => $user->getAttribute('email_verified_at') ?? now()])->saveQuietly();
        if ($rememberDevice) {
            app(AuditLogger::class)->record('user.device_remembered', $user, meta: ['days' => LoginVerification::rememberDays()]);
        }

        return $me->payload($user, $user->last_active_tenant_id)->withCookie($cookie);
    }

    /** Step 2 with two-factor sign-in: a code from the authenticator app, or a recovery code. */
    public function loginTwoFactor(Request $request, MeController $me, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);
        $user = $this->pendingUser($request);
        if (! (bool) ($request->session()->get(self::PENDING)['two_factor'] ?? false) || ! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Your sign-in has expired. Enter your email and password again.']);
        }
        $data = $request->validate(['code' => ['nullable', 'string', 'max:10'], 'recovery_code' => ['nullable', 'string', 'max:30'], 'email_code' => ['nullable', 'string', 'max:12']]);
        foreach (['login-2fa:'.$user->id => 5, 'login-2fa-ip:'.$request->ip() => 30] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.(int) ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
            }
            RateLimiter::hit($key, 300);
        }
        if (filled($data['email_code'] ?? null)) {
            // Lost authenticator: a code emailed to the account's verified address (see loginTwoFactorEmail).
            $result = $verification->check($user, (string) $data['email_code']);
            if ($result !== 'ok') {
                throw ValidationException::withMessages(['email_code' => match ($result) {
                    'expired' => 'This code has expired. Send a new one.',
                    'locked' => 'Too many wrong codes. Send a new code.',
                    default => 'That code is not correct. Check the latest email and try again.',
                }]);
            }
            $method = 'email';
        } else {
            $method = TwoFactorController::check($user, $data['code'] ?? null, $data['recovery_code'] ?? null);
        }
        RateLimiter::clear('login-2fa:'.$user->id);

        $pending = (array) $request->session()->pull(self::PENDING);
        $device = LoginVerification::appDevice($request);
        Auth::guard('web')->login($user, $device !== null || (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();
        $request->session()->put(LoginVerification::SESSION_STAMP, now()->timestamp);
        // The installed app: 90 days on this phone, as with the emailed code.
        $cookie = $device !== null ? $verification->trust($user, true, $device) : null;
        if ($device !== null) {
            LoginVerification::stamp($request, now()->timestamp, LoginVerification::validUntil(true, $device), $device);
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        if ($method === 'recovery' || $method === 'email') {
            // Probably a lost phone: for a short while this session may replace the authenticator without another code.
            $request->session()->put(TwoFactorController::RECOVERED, now()->timestamp);
            if ($method === 'recovery') {
                app(AuditLogger::class)->record('user.two_factor_recovery_code_used', $user, meta: ['remaining' => count((array) $user->getAttribute('two_factor_recovery_codes'))]);
            } else {
                app(AuditLogger::class)->record('user.two_factor_email_code_used', $user);
                try {
                    $user->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::EMAIL_SIGN_IN, $request->ip()));
                } catch (Throwable) {
                    // the sign-in itself is what matters
                }
            }
        }
        $response = $me->payload($user, $user->last_active_tenant_id);

        return $cookie !== null ? $response->withCookie($cookie) : $response;
    }

    /**
     * Lost authenticator at sign-in: email a one-time code to the account's verified address instead
     * (the same code, limits and expiry as the normal sign-in code). Recovery codes work too.
     */
    public function loginTwoFactorEmail(Request $request, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);
        $user = $this->pendingUser($request);
        if (! (bool) ($request->session()->get(self::PENDING)['two_factor'] ?? false) || ! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Your sign-in has expired. Enter your email and password again.']);
        }
        if ($user->getAttribute('email_verified_at') === null) {
            throw ValidationException::withMessages(['email_code' => 'Your email address is not verified, so a code cannot be emailed. Use a recovery code, or contact support.']);
        }

        return $this->sendLoginCode($user, $verification, resend: true);
    }

    public function loginResend(Request $request, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);
        $pending = $request->session()->get(self::PENDING);
        if (is_array($pending) && ($pending['passwordless'] ?? false) && ($pending['id'] ?? null) === null) {
            return response()->json(['data' => ['otp_required' => true, 'retry_in' => 60, 'expires_in' => LoginVerification::CODE_MINUTES * 60]], 202);
        }

        return $this->sendLoginCode($this->pendingUser($request), $verification, resend: true);
    }

    private function sendLoginCode(User $user, LoginVerification $verification, bool $resend = false): JsonResponse
    {
        try {
            $result = $verification->send($user);
        } catch (Throwable $e) {
            Log::error('Sign-in code could not be emailed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => ['code' => 'mail_unavailable', 'message' => 'We could not email your sign-in code right now. Please try again in a few minutes or contact support.']], 503);
        }
        if (! $result['sent'] && $resend) {
            $wait = $result['retry_in'];

            throw ValidationException::withMessages(['code' => $wait > 120
                ? 'You have requested too many codes. Try again in '.(int) ceil($wait / 60).' minutes.'
                : "A code was just sent. You can request another in {$wait} seconds."]);
        }

        [$name, $domain] = array_pad(explode('@', (string) $user->email, 2), 2, '');

        return response()->json(['data' => [
            'otp_required' => true,
            'email' => mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain,
            'retry_in' => $result['retry_in'],
            'expires_in' => LoginVerification::CODE_MINUTES * 60,
        ]], 202);
    }

    private function pendingUser(Request $request): User
    {
        $pending = $request->session()->get(self::PENDING);
        $user = is_array($pending) && (now()->timestamp - (int) ($pending['at'] ?? 0)) <= 900 ? User::query()->find($pending['id'] ?? null) : null;

        if (is_array($pending) && ($pending['passwordless'] ?? false) && ($pending['id'] ?? null) === null && (now()->timestamp - (int) ($pending['at'] ?? 0)) <= 900) {
            // A code asked for an address with no account: it fails like a wrong code.
            throw ValidationException::withMessages(['code' => 'That code is not correct. Check the latest email and try again.']);
        }
        if (! $user instanceof User || $user->isDisabled()) {
            throw ValidationException::withMessages(['code' => 'Your sign-in has expired. Enter your email and password again.']);
        }

        return $user;
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function ensureStateful(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Session authentication is only available to first-party SPA origins.');
        }
    }
}
