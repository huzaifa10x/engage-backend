<?php

declare(strict_types=1);

use App\Http\Controllers\Collector\CollectorController;
use Illuminate\Support\Facades\Route;

/*
| Growth Hub tracker endpoints (e.10xdigital.ae, its own container in production). No session, no
| cookies, no CSRF: these are called from client websites with a public site key. Abuse limits are
| applied per site and per visitor inside the collector (Collector::limits). The throttle here is only a
| coarse per-IP backstop, set high because carriers and offices share IPs: refusing a tap loses its
| attribution silently (WhatsApp still opens). The collector has its own container, so load here
| cannot slow the app.
*/
Route::middleware('throttle:10000,1')->name('collector.')->group(function () {
    Route::get('sdk/v1/e.min.js', [CollectorController::class, 'sdk'])->name('sdk');
    Route::get('v1/config', [CollectorController::class, 'config'])->name('config');
    Route::post('v1/c', [CollectorController::class, 'click'])->name('click');
    Route::post('v1/v', [CollectorController::class, 'verify'])->name('verify');
});
