<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Built-in roles (roles.tenant_id IS NULL): Owner, Admin, Manager, Agent, Marketing, Viewer. Workspaces
 * on a plan with RBAC also make their own roles (item 13), starting from one of these.
 * Which numbers a member may act on is a separate grant (membership_number_access, Phase 2).
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Agent = 'agent';
    case Marketing = 'marketing';
    case Viewer = 'viewer';
    case Manager = 'manager';   // batch 6, item 13: runs a team; no billing, settings or API keys

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
                // Phone numbers: unless the workspace hides them from Agents (Settings › Team, item 13).
                Permission::ContactsViewPhone->value,
                Permission::TemplatesView->value,
                Permission::StagesChange->value, // move a lead through the stages; Won needs a verified payment (item 4)
            ],
            // Runs a team: every chat on their numbers (and assigns them), contacts, reports, stages. No
            // billing, settings, API keys, manual payments or Won without a verified payment by default.
            self::Manager => [
                Permission::InboxView->value, Permission::InboxReply->value, Permission::InboxAssign->value,
                Permission::ContactsView->value, Permission::ContactsCreate->value, Permission::ContactsUpdate->value,
                Permission::ContactsImport->value, Permission::ContactsExport->value, Permission::ContactsViewPhone->value,
                Permission::StagesChange->value,
                Permission::TemplatesView->value, Permission::CampaignsView->value, Permission::ChatbotsView->value,
                Permission::AnalyticsView->value, Permission::GrowthView->value, Permission::TeamView->value,
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
                Permission::StagesChange->value, Permission::ContactsViewPhone->value,
            ],
            self::Viewer => [
                Permission::InboxView->value, Permission::ContactsView->value, Permission::TemplatesView->value,
                Permission::CampaignsView->value, Permission::AnalyticsView->value, Permission::ChatbotsView->value,
                Permission::ContactsViewPhone->value,
            ],
        };
    }

    /** Implicitly granted access to every WhatsApp number in the tenant. */
    public function hasAllNumbers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
