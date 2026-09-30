<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Bootstraps the first Super Admin in a fresh environment (production has no seeded admin). */
final class CreatePlatformAdmin extends Command
{
    protected $signature = 'engage:admin:create {email?} {--name=} {--role=super_admin}';

    protected $description = 'Create a Super Admin (platform staff) account. Two-factor is enrolled on first login.';

    public function handle(): int
    {
        $email = mb_strtolower((string) ($this->argument('email') ?? text('Email', required: true)));
        $name = (string) ($this->option('name') ?? text('Name', required: true));
        $role = PlatformRole::tryFrom((string) $this->option('role'));
        $secret = password('Password (min 12, mixed case, numbers)', required: true);

        $validator = Validator::make(['email' => $email, 'password' => $secret], [
            'email' => ['required', 'email:rfc', 'unique:platform_admins,email'],
            'password' => [Password::min(12)->mixedCase()->numbers()],
        ]);

        if ($role === null || $validator->fails()) {
            $this->components->error($role === null ? 'Unknown role.' : $validator->errors()->first());

            return self::FAILURE;
        }

        PlatformAdmin::query()->create(['name' => $name, 'email' => $email, 'role' => $role, 'password' => $secret]);
        $this->components->info("{$role->label()} {$email} created. Sign in at /admin/login to enrol two-factor.");

        return self::SUCCESS;
    }
}
