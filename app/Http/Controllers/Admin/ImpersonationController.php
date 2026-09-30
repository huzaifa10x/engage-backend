<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Platform\Impersonation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ImpersonationController extends Controller
{
    /** Starts a support session and sends the admin's browser to the client app. */
    public function store(Request $request, Tenant $tenant, Impersonation $impersonation): Response
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        /** @var TenantMembership $membership */
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->with('user')->findOrFail($data['membership_id']);

        abort_if(! $tenant->status->isUsable(), 422, 'Reactivate the company before starting a support session.');
        abort_if($membership->user === null || $membership->user->isDisabled() || ! $membership->isActive(), 422, 'This member cannot be impersonated.');

        /** @var PlatformAdmin $admin */
        $admin = $request->user('admin');
        $result = $impersonation->start($admin, $membership, $data['reason'], $request->ip());

        return Inertia::location(config('engage.frontend_url').'/impersonate?token='.$result['token']);
    }
}
