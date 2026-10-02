<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Tenancy\RegisterWorkspace;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SystemRoleSeeder::class, PlanCatalogSeeder::class]);

        if (! app()->environment('local')) {
            return;
        }

        // Local demo only. Never seeded in staging/production.
        if (! User::query()->where('email', 'owner@engage.test')->exists()) {
            app(RegisterWorkspace::class)([
                'name' => 'Demo Owner',
                'email' => 'owner@engage.test',
                'password' => 'Password123!',
                'company_name' => 'Demo Workspace',
                'timezone' => 'Asia/Dubai',
                'country' => 'AE',
            ]);
        }

        // Connected demo number, contacts and conversations for UI work (idempotent).
        $this->call(DemoWorkspaceSeeder::class);

        PlatformAdmin::query()->firstOrCreate(['email' => 'admin@engage.test'], [
            'name' => 'Platform Admin',
            'password' => 'Password123!',
            'role' => PlatformRole::SuperAdmin,
        ]);
    }
}
