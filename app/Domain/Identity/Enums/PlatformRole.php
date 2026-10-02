<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/** Roles for 10X staff in the Super Admin — separate from tenant roles. */
enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Support = 'support';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Operations => 'Operations',
            self::Support => 'Support',
            self::Finance => 'Finance',
        };
    }

    /** @return list<AdminAbility> */
    public function abilities(): array
    {
        return match ($this) {
            self::SuperAdmin => AdminAbility::cases(),
            self::Operations => collect(AdminAbility::cases())->reject(fn (AdminAbility $a) => $a === AdminAbility::TeamManage)->values()->all(),
            self::Support => [AdminAbility::CompaniesView, AdminAbility::Impersonate, AdminAbility::UsersView, AdminAbility::AuditView, AdminAbility::SystemView],
            self::Finance => [AdminAbility::CompaniesView, AdminAbility::BillingView, AdminAbility::AuditView],
        };
    }

    public function can(AdminAbility $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }
}
