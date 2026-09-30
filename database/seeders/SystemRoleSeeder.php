<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Idempotent: safe to run on every deploy to sync system-role permissions. */
final class SystemRoleSeeder extends Seeder
{
    public function run(TenantContext $context): void
    {
        $context->bypass(function (): void {
            foreach (SystemRole::cases() as $role) {
                Role::query()->updateOrCreate(
                    ['tenant_id' => null, 'key' => $role->value],
                    ['name' => $role->label(), 'permissions' => $role->permissions(), 'is_system' => true],
                );
            }
        });
    }
}
