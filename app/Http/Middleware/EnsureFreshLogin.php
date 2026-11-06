<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\LoginVerification;
use App\Domain\Identity\Models\User;
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
        $user = $request->hasSession() ? Auth::guard('web')->user() : null;
        // Signed out everywhere by support (or a password set by support): this session is over.
        if ($user instanceof User && $user->sessionRevoked($request->session()->get(User::SIGNED_IN_AT))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['error' => ['code' => 'session_expired', 'message' => 'You were signed out. Please sign in again.']], 401);
        }

        if (! LoginVerification::enabled() || $user === null) {
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
