<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\TwoFactor\Totp;
use App\Http\Controllers\Controller;
use App\Notifications\TwoFactorChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Two-factor sign-in for portal users (authenticator app, with one-time recovery codes). Required
 * for workspace Owners (engage.auth.owner_two_factor_required); anyone else may turn it on.
 *
 *   GET    auth/two-factor                  status
 *   POST   auth/two-factor/setup            a new secret + otpauth link (kept in the session until confirmed)
 *   POST   auth/two-factor/confirm          a code from the new app → turned on (or replaced), recovery codes shown once
 *   POST   auth/two-factor/replace          the current factor → a new secret for another phone or app
 *   POST   auth/two-factor/recovery-codes   the current factor → new recovery codes
 *   DELETE auth/two-factor                  the current factor → turned off (not for Owners while required)
 *
 * "The current factor" is a code from the authenticator app or one of the recovery codes. Replacing
 * is two steps: the old authenticator keeps working until a code from the new one is confirmed, so a
 * replacement that is abandoned half-way never locks anyone out. Someone who lost their phone signs
 * in with a recovery code and may then replace the authenticator for 15 minutes without another code.
 * Every change is audited and emailed to the user.
 */
final class TwoFactorController extends Controller
{
    private const SETUP = 'auth.2fa.setup_secret';

    /** When the current factor was proven for a replacement (unix time). */
    private const REPLACE = 'auth.2fa.replace_verified';

    /** When this session signed in with a recovery code (unix time). Set by AuthController. */
    public const RECOVERED = 'auth.2fa.recovered_at';

    /** Minutes a proven factor (or a recovery-code sign-in) allows the replacement to finish. */
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::status($this->user($request))]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is already on. Use “Replace authenticator” to move it to another phone or app.']);
        }

        return response()->json(['data' => $this->newSecret($request, $user)]);
    }

    /**
     * Step 1 of a replacement: prove the current factor (app code or recovery code; not needed right
     * after signing in with a recovery code), then scan the new secret.
     */
    public function replace(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->refuseDuringSupport();
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is not on. Turn it on instead.']);
        }
        if (self::recoveredRecently($request)) {
            $via = 'recovery_sign_in';
        } else {
            $via = $this->verifyCurrent($request, $user);
        }
        $request->session()->put(self::REPLACE, ['at' => now()->timestamp, 'via' => $via]);

        return response()->json(['data' => $this->newSecret($request, $user)]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $secret = $request->session()->get(self::SETUP);
        if (! is_string($secret)) {
            throw ValidationException::withMessages(['code' => 'Start the setup again: scan the new QR code.']);
        }
        $replacing = $user->hasTwoFactor();
        $proof = (array) $request->session()->get(self::REPLACE);
        if ($replacing) {
            $this->refuseDuringSupport();
            if (! is_int($proof['at'] ?? null) || $proof['at'] < now()->subMinutes(self::WINDOW_MINUTES)->timestamp) {
                $request->session()->forget([self::SETUP, self::REPLACE]);
                throw ValidationException::withMessages(['code' => 'This replacement took too long. Start again: your current authenticator still works.']);
            }
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
        $request->session()->forget([self::SETUP, self::REPLACE, self::RECOVERED]);

        if ($replacing) {
            $this->audit->record('user.two_factor_replaced', $user, meta: ['verified_with' => (string) ($proof['via'] ?? 'app')]);
            $this->tell($user, TwoFactorChangedNotification::REPLACED);
        } else {
            $this->audit->record('user.two_factor_enabled', $user);
            $this->tell($user, TwoFactorChangedNotification::ENABLED);
        }

        return response()->json(['data' => self::status($user) + ['recovery_codes' => $codes]]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->refuseDuringSupport();
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is not on.']);
        }
        $this->verifyCurrent($request, $user);
        $codes = Totp::recoveryCodes(10);
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $this->audit->record('user.two_factor_recovery_codes_renewed', $user);
        $this->tell($user, TwoFactorChangedNotification::CODES_RENEWED);

        return response()->json(['data' => self::status($user) + ['recovery_codes' => $codes]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->refuseDuringSupport();
        if ($user->twoFactorRequired()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is required for workspace Owners and cannot be turned off. You can replace the authenticator instead.']);
        }
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is not on.']);
        }
        $this->verifyCurrent($request, $user);
        $user->clearTwoFactor();
        $request->session()->forget([self::SETUP, self::REPLACE, self::RECOVERED]);
        $this->audit->record('user.two_factor_disabled', $user);
        $this->tell($user, TwoFactorChangedNotification::DISABLED);

        return response()->json(['data' => self::status($user)]);
    }

    /**
     * @return array{enabled: bool, required: bool, confirmed_at: ?string, last_used_at: ?string, recovery_codes_left: int, recovery_sign_in: bool}
     */
    public static function status(User $user): array
    {
        $step = $user->getAttribute('two_factor_last_used_step');
        $request = request();

        return [
            'enabled' => $user->hasTwoFactor(),
            'required' => $user->twoFactorRequired(),
            'confirmed_at' => $user->getAttribute('two_factor_confirmed_at')?->toIso8601String(),
            'last_used_at' => is_numeric($step) && (int) $step > 0 ? Carbon::createFromTimestamp((int) $step * Totp::PERIOD)->toIso8601String() : null,
            'recovery_codes_left' => count((array) $user->getAttribute('two_factor_recovery_codes')),
            // Signed in with a recovery code a moment ago: the phone is probably lost, offer the replacement.
            'recovery_sign_in' => $user->hasTwoFactor() && $request->hasSession() && self::recoveredRecently($request),
        ];
    }

    /**
     * A code from the app, or a recovery code (used up). Returns the method used, or throws.
     */
    public static function check(User $user, ?string $code, ?string $recovery): string
    {
        if (filled($recovery)) {
            $codes = (array) $user->getAttribute('two_factor_recovery_codes');
            $given = mb_strtolower(str_replace(' ', '', trim((string) $recovery)));
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

    private static function recoveredRecently(Request $request): bool
    {
        $at = $request->session()->get(self::RECOVERED);

        return is_int($at) && $at >= now()->subMinutes(self::WINDOW_MINUTES)->timestamp;
    }

    /** @return array{secret: string, otpauth_uri: string} */
    private function newSecret(Request $request, User $user): array
    {
        $secret = Totp::generateSecret();
        $request->session()->put(self::SETUP, $secret);

        return [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'otpauth_uri' => Totp::provisioningUri($secret, (string) $user->email, '10X Engage'),
        ];
    }

    /** The app code ("code") or a recovery code ("recovery_code"). Returns "app" or "recovery". */
    private function verifyCurrent(Request $request, User $user): string
    {
        $data = $request->validate([
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:30'],
        ], ['code.required_without' => 'Enter the current code from your authenticator app, or a recovery code.']);
        $this->limit($user);

        return self::check($user, $data['code'] ?? null, $data['recovery_code'] ?? null);
    }

    /** Support staff signed in as the user cannot change how the user signs in. */
    private function refuseDuringSupport(): void
    {
        if (Context::hasHidden('impersonator_id')) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in can only be changed by the user. Super Admins can reset it from the admin panel.']);
        }
    }

    private function tell(User $user, string $change): void
    {
        try {
            $user->notify(new TwoFactorChangedNotification($change, request()->ip()));
        } catch (Throwable $e) {
            Log::warning('Could not email a two-factor change.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
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
