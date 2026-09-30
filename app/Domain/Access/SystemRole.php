<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Built-in roles (roles.tenant_id IS NULL). Tenant custom roles arrive with Full RBAC (Growth+).
 * Which numbers a member may act on is a separate grant (membership_number_access, Phase 2).
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Agent = 'agent';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<string> '*' = every permission, including future ones */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ['*'],
            self::Admin => array_values(array_diff(Permission::values(), [Permission::BillingManage->value])),
            self::Agent => [
                Permission::InboxView->value, Permission::InboxReply->value,
                Permission::ContactsView->value, Permission::ContactsCreate->value, Permission::ContactsUpdate->value,
                Permission::TemplatesView->value,
            ],
            self::Viewer => [
                Permission::InboxView->value, Permission::ContactsView->value, Permission::TemplatesView->value,
                Permission::CampaignsView->value, Permission::AnalyticsView->value,
            ],
        };
    }

    /** Implicitly granted access to every WhatsApp number in the tenant. */
    public function hasAllNumbers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
