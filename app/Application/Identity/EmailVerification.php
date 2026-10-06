<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Email verification by one-time code (OTP).
 *
 *  • a 6-digit code, emailed; only its hash is stored, for 10 minutes;
 *  • a code dies after 5 wrong guesses (a new one must be requested);
 *  • a new code at most once a minute and 5 times an hour per account;
 *  • a new code replaces the previous one, and a code works exactly once.
 */
final class EmailVerification
{
    public const MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const MAX_SENDS_PER_HOUR = 5;

    /**
     * Emails a fresh code. Returns the seconds to wait when a send limit applies (nothing is sent).
     *
     * @return array{sent: bool, retry_in: int}
     */
    public function send(User $user): array
    {
        if ($user->getAttribute('email_verified_at') !== null) {
            return ['sent' => false, 'retry_in' => 0];
        }

        $minute = 'otp-send:'.$user->id;
        $hour = 'otp-send-hour:'.$user->id;
        if (RateLimiter::tooManyAttempts($minute, 1)) {
            return ['sent' => false, 'retry_in' => RateLimiter::availableIn($minute)];
        }
        if (RateLimiter::tooManyAttempts($hour, self::MAX_SENDS_PER_HOUR)) {
            return ['sent' => false, 'retry_in' => RateLimiter::availableIn($hour)];
        }
        RateLimiter::hit($minute, self::RESEND_SECONDS);
        RateLimiter::hit($hour, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(self::MINUTES);
        Cache::put($this->key($user), ['hash' => Hash::make($code), 'attempts' => 0, 'email' => mb_strtolower((string) $user->email), 'expires_at' => $expires->timestamp], $expires);

        $user->notify(new VerifyEmailNotification($code, (string) $user->name, self::MINUTES));

        return ['sent' => true, 'retry_in' => self::RESEND_SECONDS];
    }

    /** @return 'verified'|'already'|'invalid'|'expired'|'locked' */
    public function verify(User $user, string $code): string
    {
        if ($user->getAttribute('email_verified_at') !== null) {
            return 'already';
        }

        $stored = Cache::get($this->key($user));
        // No code, or the account's email changed since it was sent.
        if (! is_array($stored) || ($stored['email'] ?? null) !== mb_strtolower((string) $user->email) || (int) ($stored['expires_at'] ?? 0) <= now()->timestamp) {
            return 'expired';
        }
        if ((int) $stored['attempts'] >= self::MAX_ATTEMPTS) {
            return 'locked';
        }

        if (! Hash::check(preg_replace('/\D+/', '', $code) ?? '', (string) $stored['hash'])) {
            $stored['attempts'] = (int) $stored['attempts'] + 1;
            // Keep the original expiry: wrong guesses must not extend the code's life.
            Cache::put($this->key($user), $stored, Carbon::createFromTimestamp((int) $stored['expires_at']));

            return $stored['attempts'] >= self::MAX_ATTEMPTS ? 'locked' : 'invalid';
        }

        Cache::forget($this->key($user)); // single use
        $user->forceFill(['email_verified_at' => now()])->save();

        return 'verified';
    }

    private function key(User $user): string
    {
        return 'email-otp:'.$user->id;
    }
}
