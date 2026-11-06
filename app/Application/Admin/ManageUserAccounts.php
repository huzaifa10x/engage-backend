<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Notifications\AccountChangedBySupportNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What a Super Admin can change on a client portal user: profile, email, password, verification and
 * sessions. Every change is audited (passwords never are) and the user is told by email.
 */
final class ManageUserAccounts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{name: string, email: string, timezone?: ?string, email_verified?: bool} $data */
    public function updateProfile(User $user, array $data): void
    {
        $email = mb_strtolower(trim($data['email']));
        $taken = User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->id)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['email' => 'Another user already signs in with this email.']);
        }

        $before = ['name' => $user->name, 'email' => $user->email, 'timezone' => $user->getAttribute('timezone'), 'email_verified' => $user->getAttribute('email_verified_at') !== null];
        $oldEmail = (string) $user->email;
        $emailChanged = $email !== mb_strtolower($oldEmail);
        $verified = (bool) ($data['email_verified'] ?? $before['email_verified']);

        $user->forceFill([
            'name' => trim($data['name']),
            'email' => $email,
            'timezone' => array_key_exists('timezone', $data) ? ($data['timezone'] ?: null) : $user->getAttribute('timezone'),
            'email_verified_at' => $verified ? ($emailChanged ? now() : ($user->getAttribute('email_verified_at') ?? now())) : null,
        ])->save();

        $after = ['name' => $user->name, 'email' => $user->email, 'timezone' => $user->getAttribute('timezone'), 'email_verified' => $verified];
        if ($before === $after) {
            return;
        }
        $this->audit->record('user.updated_by_support', $user, before: $before, after: $after);

        if ($emailChanged) {
            // Both addresses hear about it: the old one in case this was not the owner's wish.
            $this->tell(fn () => Notification::route('mail', $oldEmail)->notify(new AccountChangedBySupportNotification(AccountChangedBySupportNotification::EMAIL, $email)));
            $this->tell(fn () => $user->notify(new AccountChangedBySupportNotification(AccountChangedBySupportNotification::EMAIL, $email)));
        }
    }

    /** A new password chosen by support. Every existing session of the user ends. */
    public function setPassword(User $user, #[\SensitiveParameter] string $password, string $reason): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'sessions_revoked_at' => now()])->save();
        $this->audit->record('user.password_set_by_support', $user, meta: ['reason' => $reason]);
        $this->tell(fn () => $user->notify(new AccountChangedBySupportNotification(AccountChangedBySupportNotification::PASSWORD)));
    }

    /** The normal "choose a new password" email, sent on the user's behalf. */
    public function sendPasswordResetLink(User $user): void
    {
        $status = Password::broker()->sendResetLink(['email' => mb_strtolower((string) $user->email)]);
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => 'A reset link was sent a moment ago. Wait a minute before sending another.']);
        }
        $this->audit->record('user.password_reset_link_sent_by_support', $user);
    }

    public function markEmailVerified(User $user): void
    {
        if ($user->getAttribute('email_verified_at') !== null) {
            return;
        }
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->audit->record('user.email_verified_by_support', $user);
    }

    /** Ends every browser session (and remember-me cookie) of the user; they sign in again. */
    public function signOutEverywhere(User $user, string $reason): void
    {
        $user->forceFill(['remember_token' => Str::random(60), 'sessions_revoked_at' => now()])->save();
        $this->audit->record('user.signed_out_by_support', $user, meta: ['reason' => $reason]);
    }

    private function tell(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('Could not email an account change.', ['error' => $e->getMessage()]);
        }
    }
}
