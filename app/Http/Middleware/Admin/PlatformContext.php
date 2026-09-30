<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every authenticated Super Admin request runs as the `admin` guard and inside a platform
 * bypass (cross-tenant reads). Mutations that act *as* a tenant use TenantContext::run(),
 * which suspends the bypass. Runs before route-model binding (see bootstrap/app.php).
 */
final class PlatformContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('admin');

        $admin = $request->user();
        if (! $admin instanceof PlatformAdmin || ! $admin->isActive()) {
            Auth::guard('admin')->logout();

            throw new AuthenticationException('Unauthenticated.', ['admin'], route('admin.login'));
        }

        return $this->context->bypass(fn () => $next($request));
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
