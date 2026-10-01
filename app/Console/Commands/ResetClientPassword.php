<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class ResetClientPassword extends Command
{
    protected $signature = 'engage:client:password {email?}';

    protected $description = 'Set a new password for a client portal user.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) ($this->argument('email') ?? text('Email', required: true))));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No client user with email {$email}.");

            return self::FAILURE;
        }

        $secret = password('New password (min 10, mixed case, numbers)', required: true);
        $validator = Validator::make(['password' => $secret], ['password' => [Password::min(10)->mixedCase()->numbers()]]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user->forceFill(['password' => $secret, 'remember_token' => null])->save();
        $this->components->info("Password updated for {$email}.");

        return self::SUCCESS;
    }
}
