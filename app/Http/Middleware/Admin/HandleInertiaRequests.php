<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Domain\Identity\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'admin';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'admin' => function () use ($request) {
                    $admin = $request->user('admin');

                    return $admin instanceof PlatformAdmin ? [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                        'role' => $admin->role->value,
                        'role_label' => $admin->role->label(),
                        'abilities' => $admin->abilityValues(),
                    ] : null;
                },
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'env' => app()->environment(),
        ];
    }
}
