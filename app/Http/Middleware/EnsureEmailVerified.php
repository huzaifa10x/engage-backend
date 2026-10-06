<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend enforcement of "verify your email before using the app": every workspace endpoint
 * answers 403 `email_unverified` until the address has been confirmed. (Signing in, reading
 * /me, resending the email and signing out stay available.)
 */
final class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->getAttribute('email_verified_at') === null) {
            return response()->json(['error' => [
                'code' => 'email_unverified',
                'message' => 'Verify your email address to use 10X Engage. Check your inbox for the verification link.',
            ]], 403);
        }

        return $next($request);
    }
}
