<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Team\AcceptInvitation;
use App\Application\Team\InviteMember;
use App\Application\Team\RevokeInvitation;
use App\Domain\Access\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Invitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InviteMemberRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Http\Resources\Api\V1\MembershipResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class InvitationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return InvitationResource::collection(Invitation::query()->pending()->with('role')->latest()->get());
    }

    public function store(InviteMemberRequest $request, InviteMember $invite): JsonResponse
    {
        /** @var Role $role */
        $role = $request->role();

        $result = $invite($request->string('email')->toString(), $role);

        return InvitationResource::make($result['invitation']->load('role'))->response()->setStatusCode(201);
    }

    public function destroy(Invitation $invitation, RevokeInvitation $revoke): Response
    {
        $revoke($invitation);

        return response()->noContent();
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $accept($user, $token);

        if ($request->hasSession()) {
            $request->session()->put((string) config('engage.tenancy.session_key'), $membership->tenant_id);
        }

        // Explicit 200: a reactivated membership and a newly created one are the same outcome.
        return MembershipResource::make($membership->load(['role', 'tenant']))->response()->setStatusCode(200);
    }
}
