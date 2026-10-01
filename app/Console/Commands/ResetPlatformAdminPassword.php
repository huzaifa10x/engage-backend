<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Server-side recovery for a Super Admin: new password and, optionally, fresh two-factor enrolment. */
final class ResetPlatformAdminPassword extends Command
{
    protected $signature = 'engage:admin:password {email?} {--reset-2fa : Also clear two-factor so it is enrolled again at next login}';

    protected $description = 'Set a new password for a Super Admin (optionally re-enrol two-factor).';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) ($this->argument('email') ?? text('Email', required: true))));
        $admin = PlatformAdmin::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->components->error("No Super Admin with email {$email}.");

            return self::FAILURE;
        }

        $secret = password('New password (min 12, mixed case, numbers)', required: true);
        $validator = Validator::make(['password' => $secret], ['password' => [Password::min(12)->mixedCase()->numbers()]]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $admin->forceFill(['password' => $secret, 'remember_token' => null, 'disabled_at' => null]);

        if ($this->option('reset-2fa')) {
            $admin->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
            ]);
        }

        $admin->save();
        $this->components->info("Password updated for {$email}".($this->option('reset-2fa') ? '; two-factor will be set up again at next login.' : '.'));

        return self::SUCCESS;
    }
}
