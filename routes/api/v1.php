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
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\TenantController;
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
});
