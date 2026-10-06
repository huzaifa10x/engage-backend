<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\LoginVerification;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 24-hour sign-in window, enforced on the server: a browser session is valid for the window
 * since its last one-time code. After that it is signed out (401) and the next sign-in asks for
 * a new code. Token-authenticated requests (no browser session) are not affected.
 */
final class EnsureFreshLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! LoginVerification::enabled() || ! $request->hasSession() || Auth::guard('web')->user() === null) {
            return $next($request);
        }

        $session = $request->session();
        $at = $session->get(LoginVerification::SESSION_STAMP);
        if (! is_numeric($at)) {
            // A session from before this feature (or an admin impersonation): its window starts now.
            $session->put(LoginVerification::SESSION_STAMP, now()->timestamp);

            return $next($request);
        }

        if ((int) $at <= now()->subHours(LoginVerification::windowHours())->timestamp) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return response()->json(['error' => [
                'code' => 'session_expired',
                'message' => 'For your security, please sign in again and enter the code we email you.',
            ]], 401);
        }

        return $next($request);
    }
}
