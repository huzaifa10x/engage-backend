<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\TwoFactor\Totp;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor sign-in for portal users (authenticator app, with one-time recovery codes). Required
 * for workspace Owners (engage.auth.owner_two_factor_required); anyone else may turn it on.
 *
 *   GET    auth/two-factor                  status
 *   POST   auth/two-factor/setup            a new secret + otpauth link (kept in the session until confirmed)
 *   POST   auth/two-factor/confirm          the first code → turned on, recovery codes shown once
 *   POST   auth/two-factor/recovery-codes   a current code → new recovery codes
 *   DELETE auth/two-factor                  a current code → turned off (not for Owners while required)
 */
final class TwoFactorController extends Controller
{
    private const SETUP = 'auth.2fa.setup_secret';

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::status($this->user($request))]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is already on.']);
        }
        $secret = Totp::generateSecret();
        $request->session()->put(self::SETUP, $secret);

        return response()->json(['data' => [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'otpauth_uri' => Totp::provisioningUri($secret, (string) $user->email, '10X Engage'),
        ]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $secret = $request->session()->get(self::SETUP);
        if (! is_string($secret)) {
            throw ValidationException::withMessages(['code' => 'Start the setup again: scan the new QR code.']);
        }
        $this->limit($user);
        $step = Totp::verify($secret, (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Scan the QR code again and enter the current 6-digit code.']);
        }
        $codes = Totp::recoveryCodes(10);
        $user->forceFill([
            'two_factor_secret' => $secret, 'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(), 'two_factor_last_used_step' => $step,
        ])->save();
        $request->session()->forget(self::SETUP);
        $this->audit->record('user.two_factor_enabled', $user);

        return response()->json(['data' => self::status($user) + ['recovery_codes' => $codes]]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->verifyCurrent($request, $user);
        $codes = Totp::recoveryCodes(10);
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $this->audit->record('user.two_factor_recovery_codes_renewed', $user);

        return response()->json(['data' => self::status($user) + ['recovery_codes' => $codes]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->twoFactorRequired()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is required for workspace Owners and cannot be turned off.']);
        }
        $this->verifyCurrent($request, $user);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_used_step' => null])->save();
        $this->audit->record('user.two_factor_disabled', $user);

        return response()->json(['data' => self::status($user)]);
    }

    /** @return array{enabled: bool, required: bool, confirmed_at: ?string, recovery_codes_left: int} */
    public static function status(User $user): array
    {
        return [
            'enabled' => $user->hasTwoFactor(),
            'required' => $user->twoFactorRequired(),
            'confirmed_at' => $user->getAttribute('two_factor_confirmed_at')?->toIso8601String(),
            'recovery_codes_left' => count((array) $user->getAttribute('two_factor_recovery_codes')),
        ];
    }

    /**
     * A code from the app (or, at sign-in, a recovery code). Returns the method used, or throws.
     */
    public static function check(User $user, ?string $code, ?string $recovery): string
    {
        if (filled($recovery)) {
            $codes = (array) $user->getAttribute('two_factor_recovery_codes');
            $given = mb_strtolower(trim((string) $recovery));
            $match = collect($codes)->first(fn ($c) => is_string($c) && hash_equals($c, $given));
            if ($match === null) {
                throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not valid.']);
            }
            $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$match]))])->save();

            return 'recovery';
        }
        $step = Totp::verify((string) $user->getAttribute('two_factor_secret'), (string) $code, $user->getAttribute('two_factor_last_used_step'));
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }
        $user->forceFill(['two_factor_last_used_step' => $step])->save();

        return 'app';
    }

    private function verifyCurrent(Request $request, User $user): void
    {
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is not on.']);
        }
        $this->limit($user);
        self::check($user, (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code'], null);
    }

    private function limit(User $user): void
    {
        $key = 'user-2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        RateLimiter::hit($key, 60);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
