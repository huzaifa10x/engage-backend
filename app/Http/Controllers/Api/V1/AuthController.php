<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\EmailVerification;
use App\Application\Identity\LoginVerification;
use App\Application\Tenancy\RegisterWorkspace;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
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
        $trustedSince = LoginVerification::enabled() ? $verification->trustedSince($request, $user) : now();

        if ($trustedSince === null) {
            // Password correct, but not signed in yet: park the sign-in until the code is entered.
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->put(self::PENDING, ['id' => $user->id, 'remember' => $remember, 'at' => now()->timestamp]);

            return $this->sendLoginCode($user, $verification);
        }

        $request->session()->regenerate();
        $request->session()->put(LoginVerification::SESSION_STAMP, $trustedSince->timestamp);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $me->payload($user, $user->last_active_tenant_id);
    }

    /** Step 2: the emailed code. Signs the user in and trusts this browser for 24 hours. */
    public function loginVerify(Request $request, MeController $me, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);
        $user = $this->pendingUser($request);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\s*\d[\d\s-]{4,10}\d\s*$/']], ['code.regex' => 'Enter the 6-digit code from the email.']);

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
        Auth::guard('web')->login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();
        $request->session()->put(LoginVerification::SESSION_STAMP, now()->timestamp);
        // A code read from the inbox also proves the address belongs to them.
        $user->forceFill(['last_login_at' => now(), 'email_verified_at' => $user->getAttribute('email_verified_at') ?? now()])->saveQuietly();

        return $me->payload($user, $user->last_active_tenant_id)->withCookie($verification->trust($user));
    }

    public function loginResend(Request $request, LoginVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);

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
