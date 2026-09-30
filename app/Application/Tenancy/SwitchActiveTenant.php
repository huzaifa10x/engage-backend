<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Exceptions\ImpersonationActive;
use App\Domain\Tenancy\Exceptions\MembershipSuspended;
use App\Domain\Tenancy\Exceptions\TenantUnavailable;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * The client asks to work in a tenant; the server verifies membership. The requested ID is a
 * selection hint only — it grants nothing by itself.
 */
final class SwitchActiveTenant
{
    public function __invoke(Request $request, User $user, string $tenantId): TenantMembership
    {
        if ($request->attributes->get('impersonation') !== null) {
            throw new ImpersonationActive;
        }

        /** @var TenantMembership|null $membership */
        $membership = $user->memberships()->with('tenant')->where('tenant_id', $tenantId)->first();

        if ($membership === null || $membership->tenant === null) {
            throw new AuthorizationException('You are not a member of this workspace.');
        }

        if (! $membership->isActive()) {
            throw new MembershipSuspended;
        }

        if (! $membership->tenant->status->isUsable()) {
            throw new TenantUnavailable($membership->tenant->status);
        }

        if ($request->hasSession()) {
            $request->session()->put(config('engage.tenancy.session_key'), $membership->tenant_id);
        }

        $user->forceFill(['last_active_tenant_id' => $membership->tenant_id])->save();

        return $membership;
    }
}
