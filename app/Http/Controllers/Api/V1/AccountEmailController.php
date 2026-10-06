<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\EmailVerification;
use App\Application\Identity\LoginVerification;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Email verification and password reset for workspace users. */
final class AccountEmailController extends Controller
{
    /**
     * The signed-in (but not yet verified) user enters the 6-digit code from the email. On success
     * the account is verified and the same session carries straight on into the app.
     */
    public function verify(Request $request, EmailVerification $verification, LoginVerification $login): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\s*\d[\d\s-]{4,10}\d\s*$/']], ['code.regex' => 'Enter the 6-digit code from the email.']);

        // Per account and per IP, on top of the 5 guesses each code allows.
        foreach (['otp-verify:'.$user->id => 10, 'otp-verify-ip:'.$request->ip() => 30] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.(int) ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
            }
            RateLimiter::hit($key, 900);
        }

        $result = $verification->verify($user, $data['code']);
        if ($result === 'verified' || $result === 'already') {
            RateLimiter::clear('otp-verify:'.$user->id);
            $response = response()->json(['data' => ['status' => $result]]);
            if ($result === 'verified' && $request->hasSession()) {
                // The registration code doubles as the first sign-in code: trust this browser for the window.
                $request->session()->put(LoginVerification::SESSION_STAMP, now()->timestamp);
                $response->withCookie($login->trust($user));
            }

            return $response;
        }

        throw ValidationException::withMessages(['code' => match ($result) {
            'expired' => 'This code has expired. Request a new one.',
            'locked' => 'Too many wrong codes. Request a new code.',
            default => 'That code is not correct. Check the latest email and try again.',
        }]);
    }

    public function resend(Request $request, EmailVerification $verification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->getAttribute('email_verified_at') !== null) {
            return response()->json(['data' => ['status' => 'already', 'retry_in' => 0]]);
        }

        $result = $verification->send($user);
        if (! $result['sent']) {
            $wait = $result['retry_in'];

            throw ValidationException::withMessages(['code' => $wait > 120
                ? 'You have requested too many codes. Try again in '.(int) ceil($wait / 60).' minutes.'
                : "A code was just sent. You can request another in {$wait} seconds."]);
        }

        return response()->json(['data' => ['status' => 'sent', 'retry_in' => $result['retry_in'], 'expires_in' => EmailVerification::MINUTES * 60]]);
    }

    /** Always answers the same way, so the form cannot be used to find out who has an account. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        Password::broker()->sendResetLink(['email' => mb_strtolower($data['email'])]);

        return response()->json(['data' => ['status' => 'sent']]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()],
        ]);
        $data['email'] = mb_strtolower($data['email']);

        $status = Password::broker()->reset($data + ['password_confirmation' => $request->input('password_confirmation')], function (User $user, string $password): void {
            // Opening a link sent to the address also proves the address.
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'email_verified_at' => $user->getAttribute('email_verified_at') ?? now()])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is not valid or has expired. Ask for a new one.']);
        }

        return response()->json(['data' => ['status' => 'reset']]);
    }
}
