<?php

declare(strict_types=1);

use App\Http\Middleware\Admin\EnsureAbility as AdminAbility;
use App\Http\Middleware\Admin\HandleInertiaRequests as AdminInertia;
use App\Http\Middleware\Admin\PlatformContext as AdminPlatformContext;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Api\ApiExceptionRenderer;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
        then: function () {
            // Super Admin: /admin locally, or its own subdomain (ADMIN_DOMAIN) in production.
            $admin = Route::middleware(['web', AdminInertia::class])->name('admin.');
            $domain = config('engage.admin_domain');
            ($domain ? $admin->domain($domain) : $admin->prefix('admin'))->group(base_path('routes/admin.php'));
        },
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'tenant']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Caddy in production: trust X-Forwarded-* so Laravel knows the request was HTTPS.
        // Only the edge proxy can reach the app container, so trusting the forwarder is safe.
        $middleware->trustProxies(at: '*');

        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // Sanctum SPA cookie auth for the Next.js client.
        $middleware->statefulApi();
        $middleware->prependToGroup('api', ForceJsonResponse::class);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : route('admin.login'));

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'feature' => EnsureFeature::class,
            'admin.context' => AdminPlatformContext::class,
            'admin.can' => AdminAbility::class,
        ]);

        $middleware->redirectUsersTo(fn () => route('admin.overview'));

        // Tenant context MUST be resolved after authentication and BEFORE route-model binding,
        // otherwise bound tenant-owned models are queried without a tenant scope.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AdminPlatformContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? ApiExceptionRenderer::render($e, $request) : null;
        });

        $exceptions->dontReport([
            DomainException::class,
        ]);
    })->create();
