<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\ManagePlatformAdmins;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $users = User::query()
            ->with(['memberships.tenant', 'memberships.role'])
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'disabled' => $u->isDisabled(),
                'last_login_at' => $u->getAttribute('last_login_at')?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'memberships' => $u->memberships->map(fn (TenantMembership $m) => [
                    'tenant_id' => $m->tenant_id, 'tenant' => $m->tenant?->name, 'role' => $m->role?->name,
                ])->values(),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'filters' => (object) $filters,
            'totals' => [
                'users' => User::query()->count(),
                'active_7d' => User::query()->where('last_login_at', '>=', now()->subDays(7))->count(),
                'disabled' => User::query()->whereNotNull('disabled_at')->count(),
            ],
        ]);
    }

    public function updateStatus(Request $request, User $user, ManagePlatformAdmins $manage): RedirectResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $manage->setUserStatus($user, (bool) $data['active'], $data['reason']);

        return back()->with('success', $data['active'] ? 'User re-enabled.' : 'User disabled. Their sessions stop working immediately.');
    }
}
