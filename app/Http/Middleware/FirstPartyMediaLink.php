<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a signed-in person open an attachment directly: in a new tab, from a bookmark, or from a
 * link that sends no Referer.
 *
 * Cookie sign-in for the portal is switched on per request by Sanctum, which looks at the Referer
 * or Origin header to decide whether the request comes from the portal. A link opened in a new
 * tab (or typed into the address bar) carries neither, so the session was never even read and the
 * person got "Authentication required" although they were signed in.
 *
 * For GET requests to the media endpoint only, a request with no Referer and no Origin is treated
 * as coming from the host it arrived on. This grants nothing by itself: the session cookie still
 * has to be present and valid, the workspace and number-access checks still run, and requests
 * that DO carry another site's Referer or Origin are left exactly as they are.
 */
final class FirstPartyMediaLink
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->is('api/v1/media/*')
            && ! $request->headers->has('referer') && ! $request->headers->has('origin')) {
            $request->headers->set('referer', $request->getSchemeAndHttpHost().'/');
        }

        return $next($request);
    }
}
