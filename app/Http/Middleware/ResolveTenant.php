<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Tenancy\Exceptions\MembershipSuspended;
use App\Domain\Tenancy\Exceptions\TenantRequired;
use App\Domain\Tenancy\Exceptions\TenantSelectionRequired;
use App\Domain\Tenancy\Exceptions\TenantUnavailable;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate → **Resolve Tenant** → Authorize → Entitlements → Action.
 *
 * The tenant is derived ONLY from server-side state: the SPA session's selected tenant, falling
 * back to the user's last active tenant, and always re-validated against an active membership
 * on every request. Client-sent tenant IDs / headers are never trusted.
 *
 *   tenant           — a tenant is required (409 tenant_selection_required if ambiguous)
 *   tenant:optional  — identity-level endpoints; resolve a tenant if one is selected
 *
 * Runs before SubstituteBindings (see bootstrap/app.php) so bound models are tenant-scoped.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Impersonation $impersonation,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if ($user->isDisabled()) {
            throw new AuthenticationException('Account disabled.');
        }

        // Enables the RLS path "users can see their own memberships" before a tenant is chosen.
        $this->context->setUser((string) $user->getKey());

        // Support session: the tenant is pinned to the one the admin opened; no switching.
        $impersonation = $this->impersonation->current($request);
        $request->attributes->set('impersonation', $impersonation);

        $membership = $impersonation instanceof ImpersonationSession
            ? $this->membershipFor($user, $impersonation->tenant_id) ?? throw new TenantRequired
            : $this->resolveMembership($request, $user, $mode === 'required');

        if ($membership !== null) {
            $this->assertUsable($membership);
            $this->context->set($membership->tenant, $membership);
            $membership->loadMissing('role');
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }

    private function resolveMembership(Request $request, User $user, bool $required): ?TenantMembership
    {
        $sessionKey = (string) config('engage.tenancy.session_key');
        $selected = $request->hasSession() ? $request->session()->get($sessionKey) : null;
        $selected ??= $user->last_active_tenant_id;

        if (is_string($selected) && $selected !== '') {
            $membership = $this->membershipFor($user, $selected);

            if ($membership !== null) {
                return $membership;
            }

            // Stale selection (membership removed) — forget it and fall through.
            if ($request->hasSession()) {
                $request->session()->forget($sessionKey);
            }
        }

        if (! $required) {
            return null;
        }

        $candidates = $user->memberships()->active()->with('tenant')->limit(2)->get();

        return match ($candidates->count()) {
            0 => throw new TenantRequired,
            1 => $this->remember($request, $user, $candidates->first()),
            default => throw new TenantSelectionRequired,
        };
    }

    private function membershipFor(User $user, string $tenantId): ?TenantMembership
    {
        return $user->memberships()->with('tenant')->where('tenant_id', $tenantId)->first();
    }

    private function remember(Request $request, User $user, TenantMembership $membership): TenantMembership
    {
        if ($request->hasSession()) {
            $request->session()->put((string) config('engage.tenancy.session_key'), $membership->tenant_id);
        }

        if ($user->last_active_tenant_id !== $membership->tenant_id) {
            $user->forceFill(['last_active_tenant_id' => $membership->tenant_id])->saveQuietly();
        }

        return $membership;
    }

    private function assertUsable(TenantMembership $membership): void
    {
        $tenant = $membership->tenant;

        if ($tenant === null) {
            throw new TenantRequired;
        }

        if (! $tenant->status->isUsable()) {
            throw new TenantUnavailable($tenant->status);
        }

        if (! $membership->isActive()) {
            throw new MembershipSuspended;
        }
    }
}
