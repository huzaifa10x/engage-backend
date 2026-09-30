<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use Illuminate\Validation\ValidationException;

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

    public function setUserStatus(User $user, bool $active, string $reason): void
    {
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
