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

    case AuditView = 'audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
