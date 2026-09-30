<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Client side of Super Admin impersonation. The Next.js /impersonate page POSTs the one-time
 * token here; the response is the normal /me bootstrap payload with an `impersonation` block
 * so the client can render a persistent "Support session" banner.
 */
final class ImpersonationController extends Controller
{
    public function store(Request $request, Impersonation $impersonation, MeController $me, TenantContext $context): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Session authentication is only available to first-party SPA origins.');
        }

        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $session = $impersonation->consume($data['token']);

        /** @var User $user */
        $user = $session->user;

        // Pin the request to the client guard before logging in. Sanctum's AuthenticateSession stores
        // the password hash of `$request->user()` (default guard) under `password_hash_web`; if the
        // default were anything else, the next request would see a mismatch and log the user out.
        Auth::shouldUse('web');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $session->getKey());
        $request->session()->put((string) config('engage.tenancy.session_key'), $session->tenant_id);

        Context::addHidden('impersonator_id', $session->platform_admin_id);
        Context::addHidden('impersonation_id', $session->getKey());

        return $me->payload($user, $session->tenant_id);
    }

    public function destroy(Request $request, Impersonation $impersonation): Response
    {
        $id = $request->session()->get(Impersonation::SESSION_KEY);

        if (is_string($id)) {
            $session = app(TenantContext::class)->bypass(fn () => ImpersonationSession::query()->find($id));
            if ($session instanceof ImpersonationSession && $session->ended_at === null) {
                $impersonation->end($session);
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}