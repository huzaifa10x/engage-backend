<?php

declare(strict_types=1);

use App\Domain\Access\Permission;
use App\Http\Controllers\Api\V1\ActiveTenantController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EntitlementController;
use App\Http\Controllers\Api\V1\ImpersonationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Messaging\ContactController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Messaging\MediaController;
use App\Http\Controllers\Api\V1\Messaging\MessageController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\Templates\TemplateController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WhatsApp\AccountController as WhatsAppAccountController;
use App\Http\Controllers\Api\V1\WhatsApp\PhoneNumberController;
use App\Http\Controllers\Api\V1\WhatsApp\SignupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:auth')->prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('impersonation', [ImpersonationController::class, 'store'])->name('impersonation.start');
});

/*
|--------------------------------------------------------------------------
| Authenticated, tenant optional (identity-level endpoints)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant:optional', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
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
Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
    Route::get('tenant', [TenantController::class, 'show'])->name('tenant.show');
    Route::patch('tenant', [TenantController::class, 'update'])
        ->middleware('can:'.Permission::SettingsManage->value)->name('tenant.update');

    Route::get('tenant/entitlements', [EntitlementController::class, 'index'])->name('tenant.entitlements');

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
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->name('conversations.read');
        Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->name('conversations.messages.index');
        Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
    });

    Route::middleware(['can:'.Permission::InboxReply->value, 'throttle:messaging'])->group(function () {
        Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->name('conversations.messages.store');
        Route::post('messages', [MessageController::class, 'start'])->name('messages.start');
        Route::post('media', [MediaController::class, 'store'])->name('media.store');
    });
});
