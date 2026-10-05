<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/** What platform staff may do in the Super Admin. Mapped to roles in PlatformRole::abilities(). */
enum AdminAbility: string
{
    case CompaniesView = 'companies.view';
    case CompaniesManage = 'companies.manage';     // suspend, change plan, trials, overrides
    case Impersonate = 'impersonate';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';             // disable / enable end users
    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';         // plans, prices, refunds
    case AuditView = 'audit.view';
    case SystemView = 'system.view';               // queues (Horizon), health
    case TeamManage = 'team.manage';               // platform admins
}
