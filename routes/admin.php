<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\TwoFactorController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
| Super Admin (Inertia React). Loaded by bootstrap/app.php with the `web` group, the `admin.`
| name prefix and the /admin path (or ADMIN_DOMAIN in production).
*/

Route::middleware('guest:admin')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
    Route::get('two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('two-factor/challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:admin-login')->name('two-factor.verify');
    Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:admin-login')->name('two-factor.confirm');
});

Route::middleware(['auth:admin', 'admin.context'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');

    Route::get('/', OverviewController::class)->middleware('admin.can:companies.view')->name('overview');

    Route::prefix('companies')->name('companies.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->middleware('admin.can:companies.view')->name('index');
        Route::get('{tenant}', [CompanyController::class, 'show'])->middleware('admin.can:companies.view')->name('show');

        Route::middleware('admin.can:companies.manage')->group(function () {
            Route::patch('{tenant}/status', [CompanyController::class, 'updateStatus'])->name('status');
            Route::put('{tenant}/plan', [CompanyController::class, 'updatePlan'])->name('plan');
            Route::post('{tenant}/trial', [CompanyController::class, 'extendTrial'])->name('trial');
            Route::put('{tenant}/overrides/{feature}', [CompanyController::class, 'setOverride'])->name('overrides.set');
            Route::delete('{tenant}/overrides/{feature}', [CompanyController::class, 'removeOverride'])->name('overrides.remove');
        });

        Route::post('{tenant}/impersonate', [ImpersonationController::class, 'store'])
            ->middleware(['admin.can:impersonate', 'throttle:10,1'])->name('impersonate');
    });

    Route::get('users', [UserController::class, 'index'])->middleware('admin.can:users.view')->name('users.index');
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('admin.can:users.manage')->name('users.status');

    Route::get('subscriptions', [SubscriptionController::class, 'index'])->middleware('admin.can:billing.view')->name('subscriptions.index');
    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('admin.can:audit.view')->name('audit.index');

    Route::middleware('admin.can:team.manage')->prefix('team')->name('team.')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('index');
        Route::post('/', [TeamController::class, 'store'])->name('store');
        Route::patch('{admin}', [TeamController::class, 'update'])->name('update');
        Route::post('{admin}/reset-two-factor', [TeamController::class, 'resetTwoFactor'])->name('reset-two-factor');
    });
});
