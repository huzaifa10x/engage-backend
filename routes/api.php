<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| All client/API functionality is versioned. Never add unversioned endpoints.
*/
Route::prefix('v1')->name('api.v1.')->group(base_path('routes/api/v1.php'));
