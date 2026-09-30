<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Access\Permission;
use App\Domain\Tenancy\Database\PostgresSessionVariables;
use App\Domain\Tenancy\Exceptions\TenantContextMissing;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * The single source of truth for "which tenant is this code running for".
 *
 * Resolved server-side only (ResolveTenant middleware for HTTP, Context hydration for queued
 * jobs, TenantContext::run() for CLI). Never populated from client-supplied tenant IDs.
 *
 * Three isolation layers read from it:
 *   1. TenantScope (Eloquent)         — fail-closed: querying a tenant model without context throws
 *   2. Policies / Gate permissions    — can() / authorize() against the active membership's role
 *   3. PostgreSQL RLS                 — via PostgresSessionVariables
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    private ?string $userId = null;

    private int $bypassDepth = 0;

    public function __construct(private readonly PostgresSessionVariables $database) {}

    public function set(Tenant $tenant, ?TenantMembership $membership = null): void
    {
        if ($membership !== null && $membership->tenant_id !== $tenant->getKey()) {
            throw new LogicException('Membership does not belong to the tenant being activated.');
        }

        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->userId = $membership !== null ? $membership->user_id : $this->userId;

        $this->syncContext();
        $this->syncDatabase();
    }

    /** Authenticated user without (yet) a tenant — enables the "own memberships" RLS path. */
    public function setUser(?string $userId): void
    {
        $this->userId = $userId;

        $this->syncContext();
        $this->syncDatabase();
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
        $this->userId = null;
        $this->bypassDepth = 0;

        Context::forget(['tenant_id', 'user_id']);
        $this->database->reset();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new TenantContextMissing('No tenant is active in the current context.');
    }

    public function tenantOrNull(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): string
    {
        return (string) $this->tenant()->getKey();
    }

    public function membership(): ?TenantMembership
    {
        return $this->membership;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * Execute a callback as a tenant (jobs, CLI, platform tooling), restoring the previous state.
     *
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback, ?TenantMembership $membership = null): mixed
    {
        $previous = [$this->tenant, $this->membership, $this->userId, $this->bypassDepth];

        // Running "as a tenant" is tenant-scoped even when called from inside a platform bypass
        // (e.g. a scheduler iterating all tenants) — the bypass is suspended, then restored.
        $this->bypassDepth = 0;
        $this->set($tenant, $membership);

        try {
            return $callback($tenant);
        } finally {
            [$this->tenant, $this->membership, $this->userId, $this->bypassDepth] = $previous;
            $this->syncContext();
            $this->syncDatabase();
        }
    }

    /**
     * Platform-level access across tenants (Super Admin, webhook tenant resolution, schedulers).
     * Disables TenantScope AND the RLS policies for the duration of the callback. Keep these
     * blocks small and never wrap user-controlled input in them without explicit authorization.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(callable $callback): mixed
    {
        $this->bypassDepth++;
        $this->syncDatabase();

        try {
            return $callback();
        } finally {
            $this->bypassDepth = max(0, $this->bypassDepth - 1);
            $this->syncDatabase();
        }
    }

    public function isBypassing(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * @return array{tenant: ?Tenant, membership: ?TenantMembership, user: ?string, bypass: int}
     */
    public function snapshot(): array
    {
        return [
            'tenant' => $this->tenant,
            'membership' => $this->membership,
            'user' => $this->userId,
            'bypass' => $this->bypassDepth,
        ];
    }

    /** @param array{tenant: ?Tenant, membership: ?TenantMembership, user: ?string, bypass: int} $snapshot */
    public function restore(array $snapshot): void
    {
        $this->tenant = $snapshot['tenant'];
        $this->membership = $snapshot['membership'];
        $this->userId = $snapshot['user'];
        $this->bypassDepth = $snapshot['bypass'];

        $this->syncContext();
        $this->syncDatabase();
    }

    public function can(Permission $permission): bool
    {
        if ($this->membership === null || ! $this->membership->isActive()) {
            return false;
        }

        return $this->membership->role?->grants($permission) ?? false;
    }

    /** @throws AuthorizationException */
    public function authorize(Permission $permission): void
    {
        if (! $this->can($permission)) {
            throw new AuthorizationException("Missing permission [{$permission->value}].");
        }
    }

    private function syncContext(): void
    {
        $this->tenant !== null
            ? Context::add('tenant_id', (string) $this->tenant->getKey())
            : Context::forget('tenant_id');

        $this->userId !== null
            ? Context::add('user_id', $this->userId)
            : Context::forget('user_id');
    }

    private function syncDatabase(): void
    {
        $this->database->apply(
            $this->tenant !== null ? (string) $this->tenant->getKey() : null,
            $this->userId,
            $this->bypassDepth > 0,
        );
    }
}
