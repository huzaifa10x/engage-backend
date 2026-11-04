<?php

declare(strict_types=1);

use App\Http\Controllers\Collector\CollectorController;
use Illuminate\Support\Facades\Route;

/*
| Growth Hub tracker endpoints (e.10xengage.com, its own container in production). No session, no
| cookies, no CSRF: these are called from client websites with a public site key. Abuse limits are
| applied per site and per visitor inside the collector; the throttle here is a coarse backstop.
*/
Route::middleware('throttle:600,1')->name('collector.')->group(function () {
    Route::get('sdk/v1/e.min.js', [CollectorController::class, 'sdk'])->name('sdk');
    Route::get('v1/config', [CollectorController::class, 'config'])->name('config');
    Route::post('v1/c', [CollectorController::class, 'click'])->name('click');
    Route::post('v1/v', [CollectorController::class, 'verify'])->name('verify');
});
