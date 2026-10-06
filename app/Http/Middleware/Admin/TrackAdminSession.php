<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Security\AdminSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the "where am I signed in" list current, and enforces remote sign-out: a session that
 * was ended from the Security page is logged out on its very next request.
 */
final class TrackAdminSession
{
    public function __construct(private readonly AdminSecurity $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof PlatformAdmin && ! $this->security->touchSession($request, $admin)) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->with('error', 'This session was signed out from another device.');
        }

        return $next($request);
    }
}
