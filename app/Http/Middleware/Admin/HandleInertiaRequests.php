<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Domain\Billing\Models\SalesLead;
use App\Domain\Identity\Enums\AdminAbility;
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
            // Shown as a badge in the sidebar; only computed for admins who can see Inquiries.
            'newInquiries' => function () use ($request) {
                $admin = $request->user('admin');

                return $admin instanceof PlatformAdmin && in_array(AdminAbility::InquiriesView->value, $admin->abilityValues(), true)
                    ? SalesLead::query()->where('status', 'new')->count()
                    : 0;
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'env' => app()->environment(),
        ];
    }
}
