<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Team\ChangeMemberRole;
use App\Application\Team\RemoveMember;
use App\Domain\Access\Models\Role;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMemberRoleRequest;
use App\Http\Resources\Api\V1\MembershipResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * {member} is bound AFTER ResolveTenant, so TenantScope limits binding to this tenant:
 * another tenant's membership ID is a 404, never a leak.
 */
final class TeamMemberController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $members = TenantMembership::query()
            ->with(['user', 'role'])
            ->orderBy('created_at')
            ->paginate(50);

        return MembershipResource::collection($members);
    }

    public function update(UpdateMemberRoleRequest $request, TenantMembership $member, ChangeMemberRole $change): MembershipResource
    {
        /** @var Role $role */
        $role = $request->role();

        return MembershipResource::make($change($member, $role)->load('user'));
    }

    public function destroy(TenantMembership $member, RemoveMember $remove): Response
    {
        $remove($member->load('role'));

        return response()->noContent();
    }
}
