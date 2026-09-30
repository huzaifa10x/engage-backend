<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Every Permission is a Gate ability evaluated against the ACTIVE membership's role — so the
 * same user can be Owner in one tenant and Agent in another.
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (User $user) use ($permission): bool {
                $context = $this->app->make(TenantContext::class);

                return $context->membership()?->user_id === $user->getKey() && $context->can($permission);
            });
        }
    }
}
