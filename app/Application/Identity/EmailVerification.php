<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Models\User;
use App\Notifications\VerifyEmailNotification;

/**
 * Email verification with a stateless, signed link: the token is an HMAC over the user, their
 * current email address and the expiry, so it cannot be forged, stops working when the address
 * changes, and needs no table.
 */
final class EmailVerification
{
    public const HOURS = 48;

    public function send(User $user): void
    {
        if ($user->getAttribute('email_verified_at') !== null) {
            return;
        }
        $expires = now()->addHours(self::HOURS)->timestamp;
        $url = config('engage.frontend_url').'/verify-email?'.http_build_query(['id' => $user->id, 'expires' => $expires, 'token' => $this->token($user, $expires)]);

        $user->notify(new VerifyEmailNotification($url, (string) $user->name, self::HOURS));
    }

    /** @return 'verified'|'already'|'invalid' */
    public function verify(string $id, int $expires, string $token): string
    {
        $user = User::query()->find($id);
        if (! $user instanceof User || $expires < now()->timestamp || ! hash_equals($this->token($user, $expires), $token)) {
            return 'invalid';
        }
        if ($user->getAttribute('email_verified_at') !== null) {
            return 'already';
        }
        $user->forceFill(['email_verified_at' => now()])->save();

        return 'verified';
    }

    private function token(User $user, int $expires): string
    {
        return hash_hmac('sha256', $user->id.'|'.mb_strtolower((string) $user->email).'|'.$expires, (string) config('app.key'));
    }
}
