<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\ManagePlatformAdmins;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** 10X staff accounts (Super Admin only). */
final class TeamController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('team/Index', [
            'admins' => PlatformAdmin::query()->orderBy('name')->get()->map(fn (PlatformAdmin $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'role' => $a->role->value,
                'role_label' => $a->role->label(),
                'active' => $a->isActive(),
                'two_factor' => $a->hasTwoFactor(),
                'last_login_at' => $a->last_login_at?->toIso8601String(),
            ]),
            'roles' => collect(PlatformRole::cases())->map(fn (PlatformRole $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'abilities' => array_map(fn ($a) => $a->value, $r->abilities()),
            ]),
        ]);
    }

    public function store(Request $request, ManagePlatformAdmins $manage): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('platform_admins', 'email')],
            'role' => ['required', Rule::enum(PlatformRole::class)],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()],
        ]);

        $manage->create($data['name'], mb_strtolower($data['email']), PlatformRole::from($data['role']), $data['password']);

        return back()->with('success', "{$data['name']} added. They set up two-factor on first login.");
    }

    public function update(Request $request, PlatformAdmin $admin, ManagePlatformAdmins $manage): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(PlatformRole::class)],
            'active' => ['required', 'boolean'],
        ]);

        /** @var PlatformAdmin $actor */
        $actor = $request->user('admin');
        $manage->update($actor, $admin, PlatformRole::from($data['role']), (bool) $data['active']);

        return back()->with('success', "{$admin->name} updated.");
    }

    public function resetTwoFactor(PlatformAdmin $admin, ManagePlatformAdmins $manage): RedirectResponse
    {
        $manage->resetTwoFactor($admin);

        return back()->with('success', "{$admin->name} will enrol a new authenticator on next login.");
    }
}
