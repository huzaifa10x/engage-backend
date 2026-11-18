<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Models\Role;
use App\Domain\Access\Permission;
use App\Domain\Access\PhoneVisibility;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditLogger;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings › Team › Roles (batch 6, item 13): the six system roles, and custom roles a workspace makes
 * on a plan with RBAC, starting from a system role and ticking permissions by area. Every change to a
 * role, and the workspace's "hide phone numbers from Agents" option, is recorded in the Audit log.
 */
final class RoleController extends Controller
{
    private const MAX_CUSTOM = 20;

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->orderByDesc('is_system')->orderBy('name')->get());
    }

    /** Every permission, by area, in plain words (the role editor), and the team privacy option. */
    public function catalog(): JsonResponse
    {
        $areas = [];
        foreach (Permission::cases() as $p) {
            $areas[$p->area()][] = ['key' => $p->value, 'label' => $p->label()];
        }

        return response()->json(['data' => [
            'areas' => collect($areas)->map(fn (array $permissions, string $area) => ['area' => $area, 'permissions' => $permissions])->values(),
            'system_roles' => collect(SystemRole::cases())->map(fn (SystemRole $r) => ['key' => $r->value, 'name' => $r->label(), 'permissions' => $r->permissions()])->values(),
            'hide_phone_from_agents' => PhoneVisibility::hidesFromAgents($this->context->tenant()),
            'custom_roles' => $this->customRolesIncluded(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCustomRoles();
        $data = $this->validated($request);
        if (Role::query()->whereNotNull('tenant_id')->count() >= self::MAX_CUSTOM) {
            throw ValidationException::withMessages(['name' => 'A workspace can have up to '.self::MAX_CUSTOM.' custom roles.']);
        }
        $role = Role::query()->create([
            'tenant_id' => $this->context->id(),
            'key' => 'custom-'.Str::lower(Str::random(8)),
            'name' => $data['name'],
            'permissions' => $data['permissions'],
            'is_system' => false,
        ]);
        $this->audit->record('role.created', $role, after: ['name' => $role->name, 'permissions' => $role->permissions]);

        return RoleResource::make($role)->response()->setStatusCode(201);
    }

    public function update(Request $request, Role $role): RoleResource
    {
        $this->ensureCustomRoles();
        abort_if($role->is_system, 422, 'System roles cannot be changed. Make a custom role from it instead.');
        $data = $this->validated($request, $role);
        $before = ['name' => $role->name, 'permissions' => $role->permissions];
        $role->forceFill(['name' => $data['name'], 'permissions' => $data['permissions']])->save();
        $this->audit->record('role.updated', $role, before: $before, after: ['name' => $role->name, 'permissions' => $role->permissions]);

        return RoleResource::make($role);
    }

    public function destroy(Role $role): JsonResponse
    {
        abort_if($role->is_system, 422, 'System roles cannot be deleted.');
        if (TenantMembership::query()->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Give the people with this role another role first.']);
        }
        $this->audit->record('role.deleted', null, before: ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions]);
        $role->delete();

        return response()->json(null, 204);
    }

    /** "Hide contact phone numbers from Agents" (Settings › Team). */
    public function privacy(Request $request): JsonResponse
    {
        $data = $request->validate(['hide_phone_from_agents' => ['required', 'boolean']]);
        $tenant = $this->context->tenant();
        $settings = (array) ($tenant->settings ?? []);
        $before = PhoneVisibility::hidesFromAgents($tenant);
        $settings['team'] = array_merge((array) ($settings['team'] ?? []), ['hide_phone_from_agents' => (bool) $data['hide_phone_from_agents']]);
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit->record('team.phone_visibility_changed', $tenant, before: ['hide_phone_from_agents' => $before], after: ['hide_phone_from_agents' => (bool) $data['hide_phone_from_agents']]);

        return $this->catalog();
    }

    /** @return array{name: string, permissions: list<string>} */
    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'permissions' => ['present', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ]);
        $name = trim($data['name']);
        $taken = Role::query()->whereRaw('lower(name) = lower(?)', [$name])->when($role, fn ($q) => $q->whereKeyNot($role->id))->exists();
        if ($taken || in_array(mb_strtolower($name), array_map(fn (SystemRole $r) => mb_strtolower($r->label()), SystemRole::cases()), true)) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }

        return ['name' => $name, 'permissions' => array_values(array_unique($data['permissions']))];
    }

    /** Plans with full role-based access (Growth and up) make their own roles. */
    private function customRolesIncluded(): bool
    {
        $rbac = app(EntitlementService::class)->for($this->context->tenant())->get(FeatureKey::Rbac);

        return $rbac->enabled && ($rbac->config['level'] ?? null) === 'full';
    }

    private function ensureCustomRoles(): void
    {
        if (! $this->customRolesIncluded()) {
            throw ValidationException::withMessages(['role' => 'Custom roles are included in plans with role-based access. Upgrade to make your own roles.']);
        }
    }
}
