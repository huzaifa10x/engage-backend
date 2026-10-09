<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Billing\StripeWebhookController;
use App\Http\Controllers\Integrations\StoreWebhookController;
use App\Http\Controllers\Meta\DataDeletionController;
use App\Http\Controllers\Meta\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| All client/API functionality is versioned. Never add unversioned endpoints.
*/
Route::prefix('v1')->name('api.v1.')->group(base_path('routes/api/v1.php'));

/*
| Public API for customers' own systems (API-key authentication), versioned separately from the
| portal's API so the two can change independently.
*/
Route::prefix('public/v1')->name('api.public.v1.')->group(base_path('routes/api/public.php'));

/*
| Meta-facing callbacks — their contract is Meta's, not ours, so they are not versioned.
| Configure in the App Dashboard: webhook callback URL, Data Deletion and Deauthorize callbacks.
*/
Route::prefix('webhooks')->name('webhooks.')->group(function () {
    Route::get('meta', [WebhookController::class, 'verify'])->name('meta.verify');
    Route::post('meta', [WebhookController::class, 'receive'])->name('meta.receive');
    // Stripe → payments, renewals, cancellations, refunds (signature verified in the controller).
    Route::post('stripe', StripeWebhookController::class)->name('stripe');
});

Route::prefix('meta')->name('meta.')->middleware('throttle:60,1')->group(function () {
    Route::post('data-deletion', [DataDeletionController::class, 'store'])->name('data-deletion');
    Route::get('data-deletion/{code}', [DataDeletionController::class, 'show'])->where('code', '[A-Za-z0-9]{12}')->name('data-deletion.show');
    Route::post('deauthorize', [DataDeletionController::class, 'deauthorize'])->name('deauthorize');
});

/*
| Store integrations — called by Shopify and by customers' WooCommerce stores, so the contract is
| theirs and the routes are not versioned. Every request is verified by signature in the controller.
*/
Route::prefix('integrations')->name('integrations.')->group(function () {
    Route::get('shopify/app', [StoreWebhookController::class, 'shopifyApp'])->middleware('throttle:60,1')->name('shopify.app');
    Route::get('shopify/callback', [StoreWebhookController::class, 'shopifyCallback'])->middleware('throttle:60,1')->name('shopify.callback');
    Route::post('shopify/webhook', [StoreWebhookController::class, 'shopify'])->name('shopify.webhook');
    Route::post('woocommerce/{publicId}/webhook', [StoreWebhookController::class, 'woocommerce'])->where('publicId', '[a-z0-9]{32}')->name('woocommerce.webhook');
});
