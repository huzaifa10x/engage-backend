<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Enums\AdminAbility;
use App\Domain\Identity\Models\PlatformAdmin;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /** Horizon is visible to active platform admins only (admin guard), in every environment. */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request): bool {
            $admin = $request->user('admin');

            return $admin instanceof PlatformAdmin && $admin->can(AdminAbility::SystemView);
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => $user instanceof PlatformAdmin);
    }
}
