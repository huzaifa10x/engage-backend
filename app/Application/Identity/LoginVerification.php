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
 */
final class LoginVerification
{
    public const COOKIE = 'engage_trusted';

    public const SESSION_STAMP = 'auth.login_verified_at';

    public const CODE_MINUTES = 10;

    public static function enabled(): bool
    {
        return (bool) config('engage.login_otp.enabled', true);
    }

    public static function windowHours(): int
    {
        return max(1, (int) config('engage.login_otp.window_hours', 24));
    }

    /** When this browser last entered a code for this user, if that is still inside the window. */
    public function trustedSince(Request $request, User $user): ?Carbon
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || $token === '') {
            return null;
        }
        $at = Cache::get($this->trustKey($user, $token));

        // Some cache stores hand numbers back as strings.
        return is_numeric($at) && (int) $at > now()->subHours(self::windowHours())->timestamp ? Carbon::createFromTimestamp((int) $at) : null;
    }

    /** Marks this browser as verified now and returns the cookie that proves it for the window. */
    public function trust(User $user): Cookie
    {
        $token = Str::random(64);
        $minutes = self::windowHours() * 60;
        Cache::put($this->trustKey($user, $token), now()->timestamp, now()->addMinutes($minutes));

        return cookie(self::COOKIE, $token, $minutes, '/', null, (bool) config('session.secure'), true, false, 'lax');
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
