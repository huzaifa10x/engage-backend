<?php

declare(strict_types=1);

use App\Domain\Access\Permission;
use App\Http\Controllers\Api\V1\AccountEmailController;
use App\Http\Controllers\Api\V1\ActiveTenantController;
use App\Http\Controllers\Api\V1\Analytics\AnalyticsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Campaigns\CampaignController;
use App\Http\Controllers\Api\V1\Compliance\ComplianceController;
use App\Http\Controllers\Api\V1\Crm\CrmController;
use App\Http\Controllers\Api\V1\DeveloperController;
use App\Http\Controllers\Api\V1\EntitlementController;
use App\Http\Controllers\Api\V1\ImpersonationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Messaging\ContactController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Messaging\InboxToolsController;
use App\Http\Controllers\Api\V1\Messaging\MediaController;
use App\Http\Controllers\Api\V1\Messaging\MessageController;
use App\Http\Controllers\Api\V1\PublicSiteController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\Templates\TemplateController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WhatsApp\AccountController as WhatsAppAccountController;
use App\Http\Controllers\Api\V1\WhatsApp\PhoneNumberController;
use App\Http\Controllers\Api\V1\WhatsApp\SignupController;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureFreshLogin;
use App\Http\Middleware\PlanRateLimit;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/
/*
| Public website (no sign-in): the live plan catalog for the pricing page, and the demo/contact form.
*/
Route::prefix('public')->name('public.')->group(function () {
    Route::get('plans', [PublicSiteController::class, 'plans'])->middleware('throttle:120,1')->name('plans');
    Route::post('leads', [PublicSiteController::class, 'lead'])->middleware('throttle:6,1')->name('leads');
});

Route::middleware('throttle:auth')->prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('login/verify', [AuthController::class, 'loginVerify'])->name('login.verify');
    Route::post('login/resend', [AuthController::class, 'loginResend'])->name('login.resend');
    Route::post('forgot-password', [AccountEmailController::class, 'forgot'])->name('password.forgot');
    Route::post('reset-password', [AccountEmailController::class, 'reset'])->name('password.reset');
    Route::post('impersonation', [ImpersonationController::class, 'store'])->name('impersonation.start');
});

/*
|--------------------------------------------------------------------------
| Authenticated, tenant optional (identity-level endpoints)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', EnsureFreshLogin::class, 'tenant:optional', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/email/verify', [AccountEmailController::class, 'verify'])->name('auth.email.verify');
    Route::post('auth/email/resend', [AccountEmailController::class, 'resend'])->name('auth.email.resend');
    Route::delete('auth/impersonation', [ImpersonationController::class, 'destroy'])->name('auth.impersonation.stop');
    Route::get('me', [MeController::class, 'show'])->name('me');
    Route::put('me/active-tenant', [ActiveTenantController::class, 'update'])->name('me.active-tenant');
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('invitations.accept');
});

/*
|--------------------------------------------------------------------------
| Authenticated + tenant resolved (Authenticate → Resolve Tenant → Authorize → Entitlement → Action)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', EnsureFreshLogin::class, EnsureEmailVerified::class, 'tenant', 'throttle:api', PlanRateLimit::class])->group(function () {
    Route::get('tenant', [TenantController::class, 'show'])->name('tenant.show');
    Route::patch('tenant', [TenantController::class, 'update'])
        ->middleware('can:'.Permission::SettingsManage->value)->name('tenant.update');

    Route::get('tenant/entitlements', [EntitlementController::class, 'index'])->name('tenant.entitlements');
    Route::get('tenant/auto-reply', [TenantController::class, 'autoReply'])->name('tenant.auto-reply');
    /*
    | Developer: API keys, webhook endpoints, delivery log.
    */
    Route::middleware('can:'.Permission::DeveloperView->value)->prefix('developer')->name('developer.')->group(function () {
        Route::get('/', [DeveloperController::class, 'overview'])->name('overview');
        Route::get('api-keys', [DeveloperController::class, 'keys'])->name('keys');
        Route::get('webhooks', [DeveloperController::class, 'endpoints'])->name('webhooks');
        Route::get('deliveries', [DeveloperController::class, 'deliveries'])->name('deliveries');
    });
    Route::middleware('can:'.Permission::DeveloperManage->value)->prefix('developer')->name('developer.')->group(function () {
        Route::post('api-keys', [DeveloperController::class, 'createKey'])->middleware('throttle:20,1')->name('keys.create');
        Route::delete('api-keys/{apiKey}', [DeveloperController::class, 'revokeKey'])->name('keys.revoke');
        Route::post('webhooks', [DeveloperController::class, 'createEndpoint'])->name('webhooks.create');
        Route::patch('webhooks/{endpoint}', [DeveloperController::class, 'updateEndpoint'])->name('webhooks.update');
        Route::delete('webhooks/{endpoint}', [DeveloperController::class, 'deleteEndpoint'])->name('webhooks.delete');
        Route::post('webhooks/{endpoint}/rotate-secret', [DeveloperController::class, 'rotateSecret'])->name('webhooks.rotate');
        Route::post('webhooks/{endpoint}/test', [DeveloperController::class, 'testEndpoint'])->middleware('throttle:20,1')->name('webhooks.test');
        Route::post('deliveries/{delivery}/resend', [DeveloperController::class, 'resend'])->middleware('throttle:60,1')->name('deliveries.resend');
    });

    Route::get('tenant/inbox-settings', [InboxToolsController::class, 'inboxSettings'])->name('tenant.inbox-settings');
    Route::put('tenant/inbox-settings', [InboxToolsController::class, 'updateInboxSettings'])
        ->middleware('can:'.Permission::SettingsManage->value)->name('tenant.inbox-settings.update');
    Route::put('tenant/auto-reply', [TenantController::class, 'updateAutoReply'])
        ->middleware('can:'.Permission::SettingsManage->value)->name('tenant.auto-reply.update');

    Route::get('roles', [RoleController::class, 'index'])
        ->middleware('can:'.Permission::TeamView->value)->name('roles.index');

    Route::prefix('team')->name('team.')->group(function () {
        Route::get('members', [TeamMemberController::class, 'index'])
            ->middleware('can:'.Permission::TeamView->value)->name('members.index');
        Route::patch('members/{member}', [TeamMemberController::class, 'update'])
            ->middleware('can:'.Permission::TeamManage->value)->name('members.update');
        Route::delete('members/{member}', [TeamMemberController::class, 'destroy'])
            ->middleware('can:'.Permission::TeamManage->value)->name('members.destroy');

        Route::get('invitations', [InvitationController::class, 'index'])
            ->middleware('can:'.Permission::TeamView->value)->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])
            ->middleware('can:'.Permission::TeamManage->value)->name('invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->middleware('can:'.Permission::TeamManage->value)->name('invitations.destroy');
    });

    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('can:'.Permission::AuditView->value)->name('audit-logs.index');

    /*
    | WhatsApp channels (Phase 2)
    */
    Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
        Route::middleware('can:'.Permission::ChannelsManage->value)->group(function () {
            Route::post('signups', [SignupController::class, 'store'])->middleware('throttle:10,1')->name('signups.store');
            Route::get('signups/{attempt}', [SignupController::class, 'show'])->name('signups.show');
            Route::post('signups/{attempt}/complete', [SignupController::class, 'complete'])->middleware('throttle:10,1')->name('signups.complete');
            Route::post('signups/{attempt}/cancel', [SignupController::class, 'cancel'])->name('signups.cancel');
            Route::post('accounts/{account}/refresh', [WhatsAppAccountController::class, 'refresh'])->middleware('throttle:6,1')->name('accounts.refresh');
            Route::delete('accounts/{account}', [WhatsAppAccountController::class, 'destroy'])->name('accounts.destroy');
        });

        Route::get('accounts', [WhatsAppAccountController::class, 'index'])
            ->middleware('can:'.Permission::ChannelsView->value)->name('accounts.index');
    });

    // Scoped to the member's granted numbers (owners/admins: all).
    Route::get('phone-numbers', [PhoneNumberController::class, 'index'])->name('phone-numbers.index');
    Route::get('phone-numbers/{phoneNumber}', [PhoneNumberController::class, 'show'])->name('phone-numbers.show');

    Route::middleware('can:'.Permission::TeamManage->value)->group(function () {
        Route::get('team/members/{member}/numbers', [PhoneNumberController::class, 'grants'])->name('team.members.numbers');
        Route::put('team/members/{member}/numbers', [PhoneNumberController::class, 'updateGrants'])->name('team.members.numbers.update');
    });

    /*
    | Message templates (Phase 4): mirrored from Meta per WhatsApp Business Account
    */
    Route::middleware('can:'.Permission::TemplatesView->value)->group(function () {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
        Route::post('templates/sync', [TemplateController::class, 'sync'])->middleware('throttle:12,1')->name('templates.sync');
    });
    Route::post('templates', [TemplateController::class, 'store'])
        ->middleware(['can:'.Permission::TemplatesSubmit->value, 'throttle:30,1'])->name('templates.store');
    Route::patch('templates/{template}', [TemplateController::class, 'update'])
        ->middleware(['can:'.Permission::TemplatesSubmit->value, 'throttle:30,1'])->name('templates.update');
    Route::delete('templates/{template}', [TemplateController::class, 'destroy'])
        ->middleware('can:'.Permission::TemplatesCreate->value)->name('templates.destroy');

    /*
    | Billing: plan, invoice details (company, country, VAT TRN), invoices, Stripe Checkout / Portal
    */
    Route::middleware('can:'.Permission::BillingView->value)->group(function () {
        Route::get('billing', [BillingController::class, 'show'])->name('billing.show');
        Route::get('billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices');
        Route::get('billing/payment-methods', [BillingController::class, 'paymentMethods'])->name('billing.payment-methods');
        Route::get('billing/payments', [BillingController::class, 'payments'])->name('billing.payments');
    });
    Route::middleware(['can:'.Permission::BillingManage->value, 'throttle:40,1'])->group(function () {
        Route::put('billing/details', [BillingController::class, 'updateDetails'])->name('billing.details');
        Route::post('billing/preview', [BillingController::class, 'preview'])->name('billing.preview');
        Route::post('billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
        Route::post('billing/refresh', [BillingController::class, 'refresh'])->name('billing.refresh');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        Route::post('billing/payment-methods/setup-intent', [BillingController::class, 'setupIntent'])->name('billing.payment-methods.setup');
        Route::put('billing/payment-methods/{paymentMethod}/default', [BillingController::class, 'setDefaultPaymentMethod'])
            ->where('paymentMethod', 'pm_[A-Za-z0-9]+')->name('billing.payment-methods.default');
        Route::delete('billing/payment-methods/{paymentMethod}', [BillingController::class, 'removePaymentMethod'])
            ->where('paymentMethod', 'pm_[A-Za-z0-9]+')->name('billing.payment-methods.remove');
        Route::post('billing/invoices/{invoice}/pay', [BillingController::class, 'payInvoice'])->name('billing.invoices.pay');
        Route::post('billing/invoices/{invoice}/refresh', [BillingController::class, 'refreshInvoice'])->name('billing.invoices.refresh');
    });

    /*
    | Compliance: consent ledger, opt-out keywords, retention, policy checks
    */
    Route::middleware('can:'.Permission::ComplianceView->value)->group(function () {
        Route::get('compliance/overview', [ComplianceController::class, 'overview'])->name('compliance.overview');
        Route::get('compliance/settings', [ComplianceController::class, 'settings'])->name('compliance.settings');
        Route::get('compliance/consent-events', [ComplianceController::class, 'consentEvents'])->name('compliance.consent-events');
        Route::get('compliance/consent-events/export', [ComplianceController::class, 'exportConsentEvents'])
            ->middleware('throttle:20,1')->name('compliance.consent-events.export');
    });
    Route::put('compliance/settings', [ComplianceController::class, 'updateSettings'])
        ->middleware('can:'.Permission::ComplianceManage->value)->name('compliance.settings.update');
    Route::post('conversations/{conversation}/consent-request', [ComplianceController::class, 'requestConsent'])
        ->middleware(['can:'.Permission::InboxReply->value, 'throttle:60,1'])->name('conversations.consent-request');

    /*
    | Campaigns (broadcasts) and analytics
    */
    Route::middleware('can:'.Permission::CampaignsView->value)->group(function () {
        Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('campaigns/audience', [CampaignController::class, 'audience'])->name('campaigns.audience');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::get('campaigns/{campaign}/recipients', [CampaignController::class, 'recipients'])->name('campaigns.recipients');
        Route::get('campaigns/{campaign}/export', [CampaignController::class, 'export'])->middleware('throttle:20,1')->name('campaigns.export');
        Route::post('campaigns/preview', [CampaignController::class, 'preview'])->name('campaigns.preview');
    });
    Route::middleware('can:'.Permission::CampaignsCreate->value)->group(function () {
        Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
        Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('campaigns.duplicate');
    });
    Route::middleware(['can:'.Permission::CampaignsSend->value, 'throttle:30,1'])->group(function () {
        Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
        Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
    });
    Route::get('analytics/overview', [AnalyticsController::class, 'overview'])
        ->middleware('can:'.Permission::AnalyticsView->value)->name('analytics.overview');

    /*
    | Contacts & CRM: tags, custom fields, segments, import / export
    */
    Route::middleware('can:'.Permission::ContactsView->value)->group(function () {
        Route::get('tags', [CrmController::class, 'tags'])->name('tags.index');
        Route::get('contact-fields', [CrmController::class, 'fields'])->name('contact-fields.index');
        Route::get('segments', [CrmController::class, 'segmentsIndex'])->name('segments.index');
        Route::post('segments/preview', [CrmController::class, 'previewSegment'])->name('segments.preview');
    });
    Route::middleware('can:'.Permission::ContactsUpdate->value)->group(function () {
        Route::post('tags', [CrmController::class, 'storeTag'])->name('tags.store');
        Route::delete('tags/{tag}', [CrmController::class, 'destroyTag'])->name('tags.destroy');
        Route::post('contacts/bulk-tag', [CrmController::class, 'bulkTag'])->name('contacts.bulk-tag');
        Route::post('contact-fields', [CrmController::class, 'storeField'])->name('contact-fields.store');
        Route::delete('contact-fields/{field}', [CrmController::class, 'destroyField'])->name('contact-fields.destroy');
        Route::post('segments', [CrmController::class, 'storeSegment'])->name('segments.store');
        Route::patch('segments/{segment}', [CrmController::class, 'updateSegment'])->name('segments.update');
        Route::delete('segments/{segment}', [CrmController::class, 'destroySegment'])->name('segments.destroy');
    });
    Route::post('contacts/import', [CrmController::class, 'import'])
        ->middleware(['can:'.Permission::ContactsImport->value, 'throttle:20,1'])->name('contacts.import');
    Route::get('contacts/export', [CrmController::class, 'export'])
        ->middleware(['can:'.Permission::ContactsExport->value, 'throttle:20,1'])->name('contacts.export');

    /*
    | Messaging (Phase 3): contacts, team inbox, sending, media
    */
    Route::middleware('can:'.Permission::ContactsView->value)->group(function () {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    });
    Route::post('contacts', [ContactController::class, 'store'])->middleware('can:'.Permission::ContactsCreate->value)->name('contacts.store');
    Route::patch('contacts/{contact}', [ContactController::class, 'update'])->middleware('can:'.Permission::ContactsUpdate->value)->name('contacts.update');
    Route::post('contacts/{contact}/consent', [ContactController::class, 'consent'])->middleware('can:'.Permission::ContactsUpdate->value)->name('contacts.consent');
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->middleware('can:'.Permission::ContactsDelete->value)->name('contacts.destroy');

    Route::middleware('can:'.Permission::InboxView->value)->group(function () {
        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::get('conversations/{conversation}/notes', [InboxToolsController::class, 'notes'])->name('conversations.notes');
        Route::get('canned-responses', [InboxToolsController::class, 'cannedResponses'])->name('canned-responses.index');
        Route::get('notifications', [InboxToolsController::class, 'notifications'])->name('notifications.index');
        Route::post('notifications/read', [InboxToolsController::class, 'readNotifications'])->name('notifications.read');
        Route::get('dashboard/summary', [InboxToolsController::class, 'dashboard'])->name('dashboard.summary');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->name('conversations.read');
        Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->name('conversations.messages.index');
        Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
    });

    Route::middleware(['can:'.Permission::InboxReply->value, 'throttle:messaging'])->group(function () {
        Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->name('conversations.messages.store');
        Route::post('conversations/{conversation}/notes', [InboxToolsController::class, 'addNote'])->name('conversations.notes.store');
        Route::post('conversations/{conversation}/snooze', [InboxToolsController::class, 'snooze'])->name('conversations.snooze');
        Route::delete('conversations/{conversation}/snooze', [InboxToolsController::class, 'unsnooze'])->name('conversations.unsnooze');
        Route::post('canned-responses', [InboxToolsController::class, 'saveCannedResponse'])->name('canned-responses.store');
        Route::put('canned-responses/{cannedResponse}', [InboxToolsController::class, 'saveCannedResponse'])->name('canned-responses.update');
        Route::delete('canned-responses/{cannedResponse}', [InboxToolsController::class, 'deleteCannedResponse'])->name('canned-responses.destroy');
        Route::post('messages', [MessageController::class, 'start'])->name('messages.start');
        Route::post('media', [MediaController::class, 'store'])->name('media.store');
    });
});
