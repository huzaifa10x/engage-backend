<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| The web routes serve ONLY the Super Admin (Inertia React, `admin` guard) — built in the next step.
| The client application lives in the separate Next.js project and uses /api/v1 exclusively.
*/
Route::get('/', fn () => response()->json(['service' => '10X Engage API', 'docs' => '/api/v1']));
