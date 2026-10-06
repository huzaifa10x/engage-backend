<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\EmailVerification;
use App\Application\Tenancy\RegisterWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Sanctum SPA authentication (session cookie). The Next.js client first calls
 * GET /sanctum/csrf-cookie, then these endpoints with credentials + X-XSRF-TOKEN.
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterWorkspace $register, MeController $me, EmailVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);

        $result = $register($request->validated());
        // The account exists but cannot be used until the emailed code is entered (EnsureEmailVerified).
        $verification->send($result['user']);

        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();
        $request->session()->put((string) config('engage.tenancy.session_key'), $result['tenant']->getKey());

        return $me->payload($result['user'], (string) $result['tenant']->getKey())->setStatusCode(201);
    }

    public function login(LoginRequest $request, MeController $me, EmailVerification $verification): JsonResponse
    {
        $this->ensureStateful($request);

        $user = $request->authenticate();
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        // Signed in but never verified: a fresh code is on its way (subject to the send limits).
        $verification->send($user);

        return $me->payload($user, $user->last_active_tenant_id);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function ensureStateful(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Session authentication is only available to first-party SPA origins.');
        }
    }
}
