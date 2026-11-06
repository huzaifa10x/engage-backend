<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 10X staff accounts and end-user account status. */
final class ManagePlatformAdmins
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name, string $email, PlatformRole $role, string $password): PlatformAdmin
    {
        $admin = PlatformAdmin::query()->create(['name' => $name, 'email' => $email, 'role' => $role, 'password' => $password]);
        $this->audit->record('platform_admin.created', $admin, after: ['email' => $admin->email, 'role' => $role->value]);

        return $admin;
    }

    public function update(PlatformAdmin $actor, PlatformAdmin $target, PlatformRole $role, bool $active): void
    {
        $losingSuperAdmin = $target->isSuperAdmin() && ($role !== PlatformRole::SuperAdmin || ! $active);

        if ($actor->is($target) && $losingSuperAdmin) {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own Super Admin access.']);
        }

        if ($losingSuperAdmin && $target->isActive() && $this->activeSuperAdmins() <= 1) {
            throw ValidationException::withMessages(['role' => 'At least one active Super Admin is required.']);
        }

        $before = ['role' => $target->role->value, 'active' => $target->isActive()];

        $target->forceFill(['role' => $role, 'disabled_at' => $active ? null : ($target->disabled_at ?? now())])->save();

        $this->audit->record('platform_admin.updated', $target, before: $before, after: ['role' => $role->value, 'active' => $active]);
    }

    /** Forces enrolment on next login (lost device). */
    public function resetTwoFactor(PlatformAdmin $target): void
    {
        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        $this->audit->record('platform_admin.two_factor_reset', $target);
    }

    /**
     * A portal user lost their phone and their recovery codes: turn two-factor sign-in off so they can
     * sign in with password + emailed code and set up a new authenticator (Owners are asked at once).
     */
    public function resetUserTwoFactor(User $user, string $reason): void
    {
        $had = $user->hasTwoFactor();
        $user->clearTwoFactor();
        $this->audit->record('user.two_factor_reset', $user, before: ['two_factor' => $had], after: ['two_factor' => false], meta: ['reason' => $reason]);
        try {
            $user->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::RESET));
        } catch (Throwable $e) {
            Log::warning('Could not email a two-factor reset.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Review account on/off (e.g. the account given to Meta App Review): email + password only.
     * Turning it on removes any authenticator, so nothing asks the reviewer for a code; turning it
     * off brings the normal checks back (Owners set up an authenticator on their next visit).
     */
    public function setReviewAccount(User $user, bool $on, string $reason): void
    {
        $before = $user->isReviewAccount();
        if ($on && $user->hasTwoFactor()) {
            $user->clearTwoFactor();
        }
        $user->forceFill(['review_account_at' => $on ? ($user->getAttribute('review_account_at') ?? now()) : null, 'review_account_note' => $on ? $reason : null])->save();
        $this->audit->record($on ? 'user.review_account_on' : 'user.review_account_off', $user,
            before: ['review_account' => $before], after: ['review_account' => $on], meta: ['reason' => $reason]);
    }

    public function setUserStatus(User $user, bool $active, string $reason): void
    {
        if (! $active && $user->isReviewAccount()) {
            throw ValidationException::withMessages(['reason' => 'This is a review account (given to Meta App Review) and cannot be disabled. End the review account first if you really need to.']);
        }
        $before = $user->isDisabled();
        $user->forceFill(['disabled_at' => $active ? null : now()])->save();

        if (! $active) {
            $user->tokens()->delete(); // revoke API tokens; session requests are rejected by ResolveTenant
        }

        $this->audit->record($active ? 'user.enabled' : 'user.disabled', $user,
            before: ['disabled' => $before], after: ['disabled' => ! $active], meta: ['reason' => $reason]);
    }

    private function activeSuperAdmins(): int
    {
        return PlatformAdmin::query()->where('role', PlatformRole::SuperAdmin)->whereNull('disabled_at')->count();
    }
}
