<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PublicV1\ApiDocsController;
use App\Http\Controllers\Api\PublicV1\PublicApiController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\PlanRateLimit;
use Illuminate\Support\Facades\Route;

/*
| Public API v1 — for customers' own systems. Base URL: /api/public/v1
| Authentication: "Authorization: Bearer <API key>" (created under Developer in the portal).
| Each route names the permission (scope) the key needs. The plan's API rate limit applies.
*/
// The API's own description: documentation data, Postman collection and OpenAPI. Public, no key.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('spec', [ApiDocsController::class, 'spec'])->name('spec');
    Route::get('postman.json', [ApiDocsController::class, 'postman'])->name('postman');
    Route::get('openapi.json', [ApiDocsController::class, 'openapi'])->name('openapi');
});

$key = fn (?string $scope = null) => [AuthenticateApiKey::class.($scope ? ':'.$scope : ''), PlanRateLimit::class];

Route::get('me', [PublicApiController::class, 'me'])->middleware($key())->name('me');
Route::get('phone-numbers', [PublicApiController::class, 'phoneNumbers'])->middleware($key('templates:read'))->name('phone-numbers');
Route::get('templates', [PublicApiController::class, 'templates'])->middleware($key('templates:read'))->name('templates');

Route::post('media', [PublicApiController::class, 'uploadMedia'])->middleware($key('messages:send'))->name('media.upload');
Route::post('messages', [PublicApiController::class, 'sendMessage'])->middleware($key('messages:send'))->name('messages.send');
Route::get('messages/{id}', [PublicApiController::class, 'message'])->whereUuid('id')->middleware($key('messages:read'))->name('messages.show');
Route::get('conversations', [PublicApiController::class, 'conversations'])->middleware($key('messages:read'))->name('conversations');
Route::get('conversations/{id}/messages', [PublicApiController::class, 'conversationMessages'])->whereUuid('id')->middleware($key('messages:read'))->name('conversations.messages');

Route::get('contacts', [PublicApiController::class, 'contacts'])->middleware($key('contacts:read'))->name('contacts');
Route::get('contacts/{id}', [PublicApiController::class, 'contact'])->whereUuid('id')->middleware($key('contacts:read'))->name('contacts.show');
Route::post('contacts', [PublicApiController::class, 'createContact'])->middleware($key('contacts:write'))->name('contacts.create');
Route::patch('contacts/{id}', [PublicApiController::class, 'updateContact'])->whereUuid('id')->middleware($key('contacts:write'))->name('contacts.update');
Route::post('contacts/{id}/opt-in', [PublicApiController::class, 'optIn'])->whereUuid('id')->middleware($key('contacts:write'))->name('contacts.opt-in');
Route::post('contacts/{id}/opt-out', [PublicApiController::class, 'optOut'])->whereUuid('id')->middleware($key('contacts:write'))->name('contacts.opt-out');

// Event subscriptions for automation platforms (Zapier, Make) and for code that manages its own webhooks.
Route::get('webhooks', [PublicApiController::class, 'webhooks'])->middleware($key('webhooks:manage'))->name('webhooks');
Route::post('webhooks', [PublicApiController::class, 'subscribe'])->middleware($key('webhooks:manage'))->name('webhooks.subscribe');
Route::delete('webhooks/{id}', [PublicApiController::class, 'unsubscribe'])->whereUuid('id')->middleware($key('webhooks:manage'))->name('webhooks.unsubscribe');
Route::get('webhooks/samples', [PublicApiController::class, 'webhookSamples'])->middleware($key('webhooks:manage'))->name('webhooks.samples');
