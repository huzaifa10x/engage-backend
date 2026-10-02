<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Bootstrap payload for the Next.js shell: who am I, which workspaces, which one is active,
 * what can I do there (permissions) and what does the plan allow (entitlements).
 */
final class MeController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->payload($user, $this->context->tenantOrNull()?->getKey());
    }

    public function payload(User $user, ?string $activeTenantId): JsonResponse
    {
        // Constrained by user_id; bypass only so custom roles of *other* tenants resolve their names.
        $memberships = $this->context->bypass(
            fn () => $user->memberships()->with(['tenant', 'role'])->orderBy('created_at')->get()
        );

        /** @var TenantMembership|null $active */
        $active = $memberships->firstWhere('tenant_id', $activeTenantId);

        $impersonation = request()->attributes->get('impersonation');
        $impersonationId = $impersonation?->getKey() ?? Context::getHidden('impersonation_id');
        $session = is_string($impersonationId)
            ? $this->context->bypass(fn () => ImpersonationSession::query()->with('admin')->find($impersonationId))
            : null;

        return response()->json(['data' => [
            'user' => UserResource::make($user),
            'memberships' => MembershipResource::collection($memberships),
            'active_tenant_id' => $active?->tenant_id,
            'permissions' => $active->role->permissions ?? [],
            'impersonation' => $session === null ? null : [
                'admin_name' => $session->admin?->name,
                'reason' => $session->reason,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
            'entitlements' => $active?->tenant !== null ? $this->entitlements->for($active->tenant)->toResponseArray() : null,
        ]]);
    }
}
