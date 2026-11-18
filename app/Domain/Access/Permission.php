<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Tenant-level permissions. Each case is registered as a Gate ability (AccessServiceProvider),
 * so routes use `can:team.manage` and code uses TenantContext::authorize(Permission::TeamManage).
 * Never check role names in controllers.
 */
enum Permission: string
{
    case InboxView = 'inbox.view';
    case InboxReply = 'inbox.reply';
    case InboxAssign = 'inbox.assign';

    case ContactsView = 'contacts.view';
    case ContactsCreate = 'contacts.create';
    case ContactsUpdate = 'contacts.update';
    case ContactsDelete = 'contacts.delete';
    case ContactsImport = 'contacts.import';
    case ContactsExport = 'contacts.export';

    case CampaignsView = 'campaigns.view';
    case CampaignsCreate = 'campaigns.create';
    case CampaignsSend = 'campaigns.send';

    case TemplatesView = 'templates.view';
    case TemplatesCreate = 'templates.create';
    case TemplatesSubmit = 'templates.submit';

    case ChannelsView = 'channels.view';
    case ChannelsManage = 'channels.manage';

    case AnalyticsView = 'analytics.view';

    case ComplianceView = 'compliance.view';
    case ComplianceManage = 'compliance.manage';

    case TeamView = 'team.view';
    case TeamManage = 'team.manage';

    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';

    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';

    case DeveloperView = 'developer.view';
    case DeveloperManage = 'developer.manage';

    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';

    case ChatbotsView = 'chatbots.view';
    case ChatbotsManage = 'chatbots.manage';     // edit drafts (editor)
    case ChatbotsPublish = 'chatbots.publish';   // publish, switch on/off, approve reviews (publisher)

    case AuditView = 'audit.view';

    // Growth Hub (R1). Agents have none of these: they see the source chip only.
    case GrowthView = 'growth.view';       // Leads, source details and evidence (masked)
    case GrowthManage = 'growth.manage';   // Tracking Setup: websites, numbers, verification
    case InboxRawText = 'inbox.raw_text';  // "Show raw text" audit toggle on messages

    // Lead stages (batch 6, item 4).
    case StagesChange = 'stages.change';           // move a lead through the stages
    case StagesApproveWon = 'stages.approve_won';  // mark Won without a verified payment, with a reference

    // Team access (batch 6, item 13).
    case PaymentsManual = 'payments.manual';             // enter a cash / bank transfer / card machine payment as verified
    case SuggestionsApprove = 'suggestions.approve';     // approve suggestions (used once Intelligence exists)
    case ContactsViewPhone = 'contacts.view_phone';      // see contacts' phone numbers (otherwise masked)

    /** The area a permission belongs to, for the role editor. */
    public function area(): string
    {
        return match (explode('.', $this->value)[0]) {
            'inbox' => 'Inbox',
            'contacts' => 'Contacts',
            'stages', 'payments', 'suggestions' => 'Leads and payments',
            'campaigns', 'templates' => 'Campaigns and templates',
            'chatbots' => 'Flows',
            'analytics', 'growth', 'audit' => 'Reports',
            'billing' => 'Billing',
            'channels', 'settings', 'team', 'compliance' => 'Settings',
            'developer', 'integrations' => 'Integrations and API keys',
            default => 'Other',
        };
    }

    /** What the permission lets someone do, in plain words. */
    public function label(): string
    {
        return match ($this) {
            self::InboxView => 'See chats',
            self::InboxReply => 'Reply in chats',
            self::InboxAssign => 'See every chat and assign chats (otherwise only chats assigned to them)',
            self::InboxRawText => 'Show the raw text of a message (audited)',
            self::ContactsView => 'See contacts',
            self::ContactsCreate => 'Add contacts',
            self::ContactsUpdate => 'Edit contacts',
            self::ContactsDelete => 'Delete contacts',
            self::ContactsImport => 'Import contacts',
            self::ContactsExport => 'Export contacts',
            self::ContactsViewPhone => 'See phone numbers',
            self::StagesChange => 'Change stages',
            self::StagesApproveWon => 'Approve Won without a verified payment (with a reference)',
            self::PaymentsManual => 'Enter manual payments (cash, bank transfer, card machine)',
            self::SuggestionsApprove => 'Approve suggestions',
            self::CampaignsView => 'See campaigns',
            self::CampaignsCreate => 'Create campaigns',
            self::CampaignsSend => 'Send campaigns',
            self::TemplatesView => 'See templates',
            self::TemplatesCreate => 'Create templates',
            self::TemplatesSubmit => 'Submit templates to Meta',
            self::ChatbotsView => 'See flows',
            self::ChatbotsManage => 'Build flows',
            self::ChatbotsPublish => 'Publish flows',
            self::AnalyticsView => 'See reports',
            self::GrowthView => 'See Growth Hub leads and sources',
            self::GrowthManage => 'Set up Growth Hub tracking',
            self::AuditView => 'See the audit log',
            self::BillingView => 'See billing',
            self::BillingManage => 'Manage billing and payments',
            self::ChannelsView => 'See WhatsApp numbers',
            self::ChannelsManage => 'Connect and manage WhatsApp numbers',
            self::SettingsView => 'See workspace settings',
            self::SettingsManage => 'Change workspace settings',
            self::TeamView => 'See the team',
            self::TeamManage => 'Invite people and change roles',
            self::ComplianceView => 'See consent and privacy',
            self::ComplianceManage => 'Change consent and privacy settings',
            self::DeveloperView => 'See API keys and webhooks',
            self::DeveloperManage => 'Manage API keys and webhooks',
            self::IntegrationsView => 'See integrations',
            self::IntegrationsManage => 'Connect and manage integrations',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
