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
    case Marketing = 'marketing';
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
            // Agent's inbox and contacts, plus campaigns, templates and Growth Hub (Insights → Marketing). CN-02.
            self::Marketing => [
                Permission::InboxView->value, Permission::InboxReply->value,
                Permission::ContactsView->value, Permission::ContactsCreate->value, Permission::ContactsUpdate->value,
                Permission::ContactsImport->value, Permission::ContactsExport->value,
                Permission::TemplatesView->value, Permission::TemplatesCreate->value, Permission::TemplatesSubmit->value,
                Permission::CampaignsView->value, Permission::CampaignsCreate->value, Permission::CampaignsSend->value,
                Permission::GrowthView->value,
                Permission::ChannelsView->value, // which WhatsApp accounts templates belong to (read only; Channels stays off the menu)
            ],
            self::Viewer => [
                Permission::InboxView->value, Permission::ContactsView->value, Permission::TemplatesView->value,
                Permission::CampaignsView->value, Permission::AnalyticsView->value, Permission::ChatbotsView->value,
            ],
        };
    }

    /** Implicitly granted access to every WhatsApp number in the tenant. */
    public function hasAllNumbers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
