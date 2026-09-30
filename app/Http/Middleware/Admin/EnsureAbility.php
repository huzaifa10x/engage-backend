<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Domain\Identity\Enums\AdminAbility;
use App\Domain\Identity\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ->middleware('admin.can:companies.manage') */
final class EnsureAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $admin = $request->user('admin');

        abort_unless($admin instanceof PlatformAdmin && $admin->can(AdminAbility::from($ability)), 403, 'Your platform role does not allow this action.');

        return $next($request);
    }
}
