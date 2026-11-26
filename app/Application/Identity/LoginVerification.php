<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Sign-in by password + emailed one-time code, with a 24-hour trust window per browser.
 *
 *  • the code: 6 digits, hashed at rest, valid 10 minutes, single use, dead after 5 wrong guesses;
 *  • sending: at most one a minute and five an hour per account;
 *  • trust: a browser that entered a code holds a random token (httpOnly cookie) for 24 hours;
 *    only its hash is stored. Within the window that browser signs in with the password alone.
 *    Any other browser, and this one after the window, is asked for a code again.
 *  • remember this device: ticked at sign-in, the same trust lasts 30 days instead of 24 hours.
 *    A password reset, "Sign out everywhere" (support) and "Forget remembered devices" end it.
 */
final class LoginVerification
{
    public const COOKIE = 'engage_trusted';

    public const SESSION_STAMP = 'auth.login_verified_at';

    /** Until when this session's sign-in stays valid (a remembered device: 30 days from its code). */
    public const SESSION_UNTIL = 'auth.login_verified_until';

    /** Session key: the installed-app device this session belongs to. */
    public const SESSION_DEVICE = 'auth.app_device';

    /** Sent by the installed phone app on every request: its own random device key. */
    public const DEVICE_HEADER = 'X-Engage-Device';

    public const CODE_MINUTES = 10;

    public static function enabled(): bool
    {
        return (bool) config('engage.login_otp.enabled', true);
    }

    public static function windowHours(): int
    {
        return max(1, (int) config('engage.login_otp.window_hours', 24));
    }

    public static function appDays(): int
    {
        return max(1, (int) config('engage.login_otp.app_days', 90));
    }

    /**
     * The installed app's device key, when this request comes from the installed app. Browsers (and
     * the app in a normal tab) send none.
     */
    public static function appDevice(Request $request): ?string
    {
        $key = $request->header(self::DEVICE_HEADER);

        return is_string($key) && preg_match('/^[A-Za-z0-9-]{16,64}$/', $key) === 1 ? $key : null;
    }

    public static function rememberDays(): int
    {
        return max(1, (int) config('engage.login_otp.remember_days', 30));
    }

    /**
     * When this browser last entered a code for this user and until when that counts, if it still does.
     *
     * @return ?array{at: int, until: int, remembered: bool, device: ?string}
     */
    public function trusted(Request $request, User $user): ?array
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || $token === '') {
            return null;
        }
        $stored = Cache::get($this->trustKey($user, $token));
        // Before "remember this device", the entry was the time alone (24-hour window). Some cache
        // stores hand numbers back as strings.
        if (is_numeric($stored)) {
            $stored = ['at' => (int) $stored, 'until' => (int) $stored + self::windowHours() * 3600, 'remembered' => false];
        }
        if (! is_array($stored) || ! is_numeric($stored['at'] ?? null) || ! is_numeric($stored['until'] ?? null)) {
            return null;
        }
        $at = (int) $stored['at'];
        $until = (int) $stored['until'];
        if ($until <= now()->timestamp) {
            return null;
        }
        // The installed app's 90 days hold only in that app, and only while it is the person's app
        // phone (a sign-in to the app on another phone ends it).
        $device = $stored['device'] ?? null;
        if (is_string($device) && ($device !== self::appDevice($request) || $device !== $user->getAttribute('app_device_id'))) {
            return null;
        }
        // Remembered devices end with a password reset, "Sign out everywhere" or "Forget remembered devices".
        foreach (['login_trust_reset_at', 'sessions_revoked_at'] as $column) {
            $reset = $user->getAttribute($column);
            if ($reset instanceof \DateTimeInterface && $at <= $reset->getTimestamp()) {
                return null;
            }
        }

        return ['at' => $at, 'until' => $until, 'remembered' => (bool) ($stored['remembered'] ?? false), 'device' => is_string($device) ? $device : null];
    }

    /**
     * Marks this browser as verified now and returns the cookie that proves it: for the 24-hour
     * window, or for 30 days when the person ticked "Remember this device".
     */
    public function trust(User $user, bool $remember = false, ?string $device = null): Cookie
    {
        $token = Str::random(64);
        $minutes = $device !== null ? self::appDays() * 1440 : ($remember ? self::rememberDays() * 1440 : self::windowHours() * 60);
        $until = now()->addMinutes($minutes);
        Cache::put($this->trustKey($user, $token), ['at' => now()->timestamp, 'until' => $until->timestamp, 'remembered' => $remember || $device !== null, 'device' => $device], $until);
        if ($device !== null && $device !== $user->getAttribute('app_device_id')) {
            // This phone is now the person's app phone: the app on any other phone is signed out.
            $user->forceFill(['app_device_id' => $device, 'app_device_at' => now()])->saveQuietly();
        }

        return cookie(self::COOKIE, $token, $minutes, '/', null, (bool) config('session.secure'), true, false, 'lax');
    }

    /** Until when a sign-in verified now stays valid: 90 days in the installed app, 30 on a remembered browser. */
    public static function validUntil(bool $remember, ?string $device): ?int
    {
        return $device !== null ? now()->addDays(self::appDays())->timestamp : ($remember ? now()->addDays(self::rememberDays())->timestamp : null);
    }

    /** Puts the verified sign-in on the session: since when, and until when it stays valid. */
    public static function stamp(Request $request, int $at, ?int $until = null, ?string $device = null): void
    {
        $request->session()->put(self::SESSION_STAMP, $at);
        $until !== null ? $request->session()->put(self::SESSION_UNTIL, $until) : $request->session()->forget(self::SESSION_UNTIL);
        $device !== null ? $request->session()->put(self::SESSION_DEVICE, $device) : $request->session()->forget(self::SESSION_DEVICE);
    }

    /** "Forget remembered devices": every browser must enter a code at its next sign-in. */
    public function forgetDevices(User $user): void
    {
        $user->forceFill(['login_trust_reset_at' => now()])->save();
    }

    /**
     * Emails a fresh code. Returns the wait in seconds when a send limit applies (nothing is sent).
     *
     * @return array{sent: bool, retry_in: int}
     */
    public function send(User $user): array
    {
        $minute = 'login-otp-send:'.$user->id;
        $hour = 'login-otp-send-hour:'.$user->id;
        if (RateLimiter::tooManyAttempts($minute, 1)) {
            return ['sent' => false, 'retry_in' => RateLimiter::availableIn($minute)];
        }
        if (RateLimiter::tooManyAttempts($hour, 5)) {
            return ['sent' => false, 'retry_in' => RateLimiter::availableIn($hour)];
        }
        RateLimiter::hit($minute, 60);
        RateLimiter::hit($hour, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(self::CODE_MINUTES);
        Cache::put($this->codeKey($user), ['hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => $expires->timestamp], $expires);

        // Sent now, not queued: the person is looking at the code screen, and a mail failure must surface here.
        $user->notifyNow(new LoginCodeNotification($code, self::CODE_MINUTES));

        return ['sent' => true, 'retry_in' => 60];
    }

    /** @return 'ok'|'invalid'|'expired'|'locked' */
    public function check(User $user, string $code): string
    {
        $stored = Cache::get($this->codeKey($user));
        if (! is_array($stored) || (int) ($stored['expires_at'] ?? 0) <= now()->timestamp) {
            return 'expired';
        }
        if ((int) $stored['attempts'] >= 5) {
            return 'locked';
        }
        if (! Hash::check(preg_replace('/\D+/', '', $code) ?? '', (string) $stored['hash'])) {
            $stored['attempts'] = (int) $stored['attempts'] + 1;
            Cache::put($this->codeKey($user), $stored, Carbon::createFromTimestamp((int) $stored['expires_at']));

            return $stored['attempts'] >= 5 ? 'locked' : 'invalid';
        }
        Cache::forget($this->codeKey($user)); // single use

        return 'ok';
    }

    private function codeKey(User $user): string
    {
        return 'login-otp:'.$user->id;
    }

    private function trustKey(User $user, string $token): string
    {
        return 'login-trust:'.$user->id.':'.hash('sha256', $token);
    }
}
