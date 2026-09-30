<?php

declare(strict_types=1);

use App\Http\Controllers\Meta\DataDeletionController;
use Illuminate\Support\Facades\Route;

/*
| Super Admin routes live in routes/admin.php. This file only serves public, non-API pages.
| The client application lives in the separate Next.js project and uses /api/v1 exclusively.
*/
Route::get('/', fn () => response()->json(['service' => '10X Engage API', 'docs' => '/api/v1']));

// Status page for Meta data-deletion requests (the URL returned to Meta).
Route::get('deletion-status', [DataDeletionController::class, 'page'])->name('meta.deletion-status');
