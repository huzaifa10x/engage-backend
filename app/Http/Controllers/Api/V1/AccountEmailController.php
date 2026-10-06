<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\EmailVerification;
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
    /** The link in the verification email lands on the web app, which posts its parameters here. */
    public function verify(Request $request, EmailVerification $verification): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'expires' => ['required', 'integer'],
            'token' => ['required', 'string', 'size:64'],
        ]);
        $result = $verification->verify($data['id'], (int) $data['expires'], $data['token']);

        if ($result === 'invalid') {
            throw ValidationException::withMessages(['token' => 'This verification link is not valid or has expired. Sign in and ask for a new one.']);
        }

        return response()->json(['data' => ['status' => $result]]);
    }

    public function resend(Request $request, EmailVerification $verification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->getAttribute('email_verified_at') !== null) {
            return response()->json(['data' => ['status' => 'already']]);
        }

        $key = 'verify-resend:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages(['email' => 'We just sent you an email. You can ask for another in '.RateLimiter::availableIn($key).' seconds.']);
        }
        RateLimiter::hit($key, 60);
        $verification->send($user);

        return response()->json(['data' => ['status' => 'sent']]);
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
