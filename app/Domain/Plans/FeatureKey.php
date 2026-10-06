<?php

declare(strict_types=1);

namespace App\Domain\Plans;

use App\Domain\Plans\Enums\FeatureType;

/**
 * Every gate in the product references one of these keys — never a plan name.
 * Source: Implementation Blueprint v2, "Full feature matrix".
 *
 * Deliberately NOT keys (never gated, blueprint "non-gating rule"): shared inbox & live chat,
 * receiving/replying, stored contacts (uncapped on every plan), mobile/PWA access.
 *
 * Adding a feature: add a case + label, add it to PlanCatalogSeeder, publish a new plan version.
 */
enum FeatureKey: string
{
    // Capacity (limit)
    case WhatsappNumbers = 'whatsapp_numbers';
    case TeamSeats = 'team_seats';
    case CannedResponses = 'canned_responses';
    case MessageTemplates = 'message_templates';
    case SavedSegments = 'saved_segments';
    case Tags = 'tags';
    case CustomFields = 'custom_fields';
    case WhatsappFlows = 'whatsapp_flows';
    case Chatbots = 'chatbots';
    case MediaStorageMb = 'media_storage_mb';
    case AuditLogRetentionDays = 'audit_log_retention_days';
    case ApiRateLimitPerMinute = 'api_rate_limit_per_minute';
    case CampaignSendRatePerHour = 'campaign_send_rate_per_hour';

    // Monthly metered levers
    case CampaignReachMonthly = 'campaign_reach_monthly';
    case AutomationExecutionsMonthly = 'automation_executions_monthly';

    // Inbox
    case Coexistence = 'coexistence';
    case ConversationAssignment = 'conversation_assignment';
    case InternalNotes = 'internal_notes';
    case Snooze = 'snooze';
    case BusinessHours = 'business_hours';
    case AutoRouting = 'auto_routing';
    case Ticketing = 'ticketing';
    case Csat = 'csat';

    // Campaigns & contacts
    case Broadcasts = 'broadcasts';
    case CampaignScheduling = 'campaign_scheduling';
    case CampaignRetry = 'campaign_retry';
    case ClickTracking = 'click_tracking';
    case Segments = 'segments';

    // Automation
    case Automations = 'automations';
    case WebChatbot = 'web_chatbot';

    // Insights & health
    case Analytics = 'analytics';
    case AgentReports = 'agent_reports';
    case NumberHealth = 'number_health';
    case LeadSourceTracking = 'lead_source_tracking';
    case QrGenerator = 'qr_generator';

    // Platform
    case Rbac = 'rbac';
    case Integrations = 'integrations';
    case ApiAccess = 'api_access';
    case Webhooks = 'webhooks';
    case Compliance = 'compliance';
    case Support = 'support';
    case Sso = 'sso';
    case WhiteLabel = 'white_label';
    case SubAccounts = 'sub_accounts';

    public function type(): FeatureType
    {
        return match ($this) {
            self::WhatsappNumbers, self::TeamSeats, self::CannedResponses, self::MessageTemplates,
            self::SavedSegments, self::Tags, self::CustomFields, self::WhatsappFlows, self::Chatbots,
            self::MediaStorageMb, self::AuditLogRetentionDays, self::ApiRateLimitPerMinute, self::CampaignSendRatePerHour => FeatureType::Limit,
            self::CampaignReachMonthly, self::AutomationExecutionsMonthly => FeatureType::Metered,
            default => FeatureType::Boolean,
        };
    }

    public function unit(): ?string
    {
        return match ($this) {
            self::MediaStorageMb => 'MB',
            self::AuditLogRetentionDays => 'days',
            self::ApiRateLimitPerMinute => 'requests/minute',
            self::CampaignSendRatePerHour => 'messages/hour',
            self::CampaignReachMonthly => 'recipients/month',
            self::AutomationExecutionsMonthly => 'executions/month',
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WhatsappNumbers => 'WhatsApp numbers',
            self::TeamSeats => 'Team seats',
            self::CannedResponses => 'Canned responses',
            self::MessageTemplates => 'Message templates',
            self::SavedSegments => 'Saved segments',
            self::Tags => 'Tags',
            self::CustomFields => 'Custom contact fields',
            self::WhatsappFlows => 'WhatsApp Flows',
            self::Chatbots => 'Chatbots',
            self::MediaStorageMb => 'Media storage',
            self::AuditLogRetentionDays => 'Audit log retention',
            self::ApiRateLimitPerMinute => 'API rate limit',
            self::CampaignSendRatePerHour => 'Campaign sending speed',
            self::CampaignReachMonthly => 'Monthly campaign reach',
            self::AutomationExecutionsMonthly => 'Automation executions',
            self::Coexistence => 'WhatsApp Coexistence',
            self::ConversationAssignment => 'Conversation assignment',
            self::InternalNotes => 'Internal notes & mentions',
            self::Snooze => 'Snooze & follow-up reminders',
            self::BusinessHours => 'Business hours',
            self::AutoRouting => 'Auto-routing',
            self::Ticketing => 'Conversation ticketing',
            self::Csat => 'CSAT',
            self::Broadcasts => 'Broadcasts',
            self::CampaignScheduling => 'Campaign scheduling',
            self::CampaignRetry => 'Campaign retry',
            self::ClickTracking => 'Click tracking',
            self::Segments => 'Contact segments',
            self::Automations => 'Automation rules',
            self::WebChatbot => 'Web chatbot',
            self::Analytics => 'Analytics',
            self::AgentReports => 'Agent performance reports',
            self::NumberHealth => 'Number health monitoring',
            self::LeadSourceTracking => 'Lead source tracking',
            self::QrGenerator => 'QR generator',
            self::Rbac => 'Roles & per-number permissions',
            self::Integrations => 'Integrations',
            self::ApiAccess => 'API access',
            self::Webhooks => 'Webhooks',
            self::Compliance => 'Compliance & consent',
            self::Support => 'Support',
            self::Sso => 'SSO / SAML',
            self::WhiteLabel => 'White-label',
            self::SubAccounts => 'Reseller sub-accounts',
        };
    }
}
