<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\TwoFactorController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\NumberController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookLogController;
use App\Http\Middleware\Admin\TrackAdminSession;
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
    Route::post('two-factor/resend', [TwoFactorController::class, 'resend'])->middleware('throttle:admin-login')->name('two-factor.resend');
    Route::post('two-factor/setup/email', [TwoFactorController::class, 'setupEmail'])->middleware('throttle:admin-login')->name('two-factor.setup-email');
    Route::post('two-factor/setup/email/confirm', [TwoFactorController::class, 'confirmEmail'])->middleware('throttle:admin-login')->name('two-factor.confirm-email');
});

Route::middleware(['auth:admin', 'admin.context', TrackAdminSession::class])->group(function () {
    // Security: every admin manages their own 2FA and sessions.
    Route::prefix('security')->name('security.')->group(function () {
        Route::get('/', [SecurityController::class, 'index'])->name('index');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('two-factor/app', [SecurityController::class, 'startApp'])->name('app.start');
            Route::post('two-factor/app/confirm', [SecurityController::class, 'confirmApp'])->name('app.confirm');
            Route::post('two-factor/email', [SecurityController::class, 'startEmail'])->name('email.start');
            Route::post('two-factor/email/confirm', [SecurityController::class, 'confirmEmail'])->name('email.confirm');
            Route::post('two-factor/cancel', [SecurityController::class, 'cancelSetup'])->name('cancel');
            Route::delete('two-factor', [SecurityController::class, 'disable'])->name('disable');
            Route::post('recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])->name('recovery-codes');
            Route::delete('sessions', [SecurityController::class, 'revokeOtherSessions'])->name('sessions.revoke-others');
            Route::delete('sessions/{session}', [SecurityController::class, 'revokeSession'])->whereUuid('session')->name('sessions.revoke');
            Route::post('test-email', [SecurityController::class, 'testEmail'])->name('test-email');
        });
    });

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

    Route::get('numbers', [NumberController::class, 'index'])->middleware('admin.can:companies.view')->name('numbers.index');
    Route::post('numbers/{number}/refresh', [NumberController::class, 'refresh'])->middleware(['admin.can:companies.manage', 'throttle:20,1'])->name('numbers.refresh');

    Route::get('webhooks', [WebhookLogController::class, 'index'])->middleware('admin.can:system.view')->name('webhooks.index');
    Route::post('webhooks/replay-failed', [WebhookLogController::class, 'replayFailed'])->middleware('admin.can:system.view')->name('webhooks.replay');

    Route::get('users', [UserController::class, 'index'])->middleware('admin.can:users.view')->name('users.index');
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('admin.can:users.manage')->name('users.status');

    Route::get('subscriptions', [SubscriptionController::class, 'index'])->middleware('admin.can:billing.view')->name('subscriptions.index');

    Route::middleware('admin.can:billing.view')->group(function () {
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    });
    Route::middleware('admin.can:billing.manage')->group(function () {
        Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
        Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}', [PlanController::class, 'edit'])->name('plans.edit');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        Route::patch('plans/{plan}/active', [PlanController::class, 'toggle'])->name('plans.toggle');
        Route::post('invoices/{invoice}/refund', [InvoiceController::class, 'refund'])->middleware('throttle:20,1')->name('invoices.refund');
    });
    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('admin.can:audit.view')->name('audit.index');

    Route::middleware('admin.can:team.manage')->prefix('team')->name('team.')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('index');
        Route::post('/', [TeamController::class, 'store'])->name('store');
        Route::patch('{admin}', [TeamController::class, 'update'])->name('update');
        Route::post('{admin}/reset-two-factor', [TeamController::class, 'resetTwoFactor'])->name('reset-two-factor');
    });
});
