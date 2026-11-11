<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\LoginVerification;
use App\Application\Team\AcceptInvitation;
use App\Application\Team\InvitationStatus;
use App\Application\Team\InviteMember;
use App\Application\Team\RegisterInvitee;
use App\Application\Team\RevokeInvitation;
use App\Domain\Access\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Exceptions\InvitationInvalid;
use App\Domain\Tenancy\Models\Invitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InviteMemberRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Rules\PersonName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

        /** @var list<string>|null $numbers */
        $numbers = $request->has('phone_number_ids') ? array_values((array) $request->input('phone_number_ids')) : null;
        $result = $invite($request->string('email')->toString(), $role, $numbers);

        return InvitationResource::make($result['invitation']->load('role'))->response()->setStatusCode(201);
    }

    public function destroy(Invitation $invitation, RevokeInvitation $revoke): Response
    {
        $revoke($invitation);

        return response()->noContent();
    }

    /** GET invitations/{token}: what the invitation page shows before accepting (no sign-in needed). */
    public function show(Request $request, string $token, InvitationStatus $status): JsonResponse
    {
        /** @var ?User $viewer */
        $viewer = $request->user('sanctum');
        $data = $status($token, $viewer);
        if ($data === null) {
            throw InvitationInvalid::because('not_found');
        }

        return response()->json(['data' => $data]);
    }

    /**
     * POST invitations/{token}/register: the short sign-up for someone invited who has no account —
     * name, password and the terms only. Joins the inviting workspace (no workspace of their own) and
     * signs in. The invited address is the account's email and counts as verified.
     */
    public function register(Request $request, string $token, RegisterInvitee $register, MeController $me): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Session authentication is only available to first-party SPA origins.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', new PersonName],
            'password' => ['required', 'string', 'max:200', Password::min(10)->mixedCase()->numbers()],
            'accept_terms' => ['accepted'],
            'timezone' => ['nullable', 'timezone:all'],
        ], ['accept_terms.accepted' => 'Accept the Terms of Service and Privacy Policy to continue.']);

        $result = $register($token, $data);
        $user = $result['user'];

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put((string) config('engage.tenancy.session_key'), $result['membership']->tenant_id);
        $request->session()->put(LoginVerification::SESSION_STAMP, now()->timestamp);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $me->payload($user, (string) $result['membership']->tenant_id)->setStatusCode(201);
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
