<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace Owners must turn on two-factor sign-in before using the workspace
 * (engage.auth.owner_two_factor_required). Identity routes (me, two-factor setup, logout) stay open,
 * so the portal can show the setup screen. A Super Admin impersonating is not stopped: the admin
 * already signed in with two factors.
 */
final class RequireOwnerTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $impersonating = $request->attributes->get('impersonation') !== null || Context::getHidden('impersonation_id') !== null;
        if ($user instanceof User && ! $impersonating && ! $user->hasTwoFactor() && $user->twoFactorRequired()) {
            return response()->json(['error' => [
                'code' => 'two_factor_setup_required',
                'message' => 'Turn on two-factor sign-in to keep using this workspace. It is required for workspace Owners.',
            ]], 403);
        }

        return $next($request);
    }
}
