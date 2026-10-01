<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Tenancy\RegisterWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates a client user with their own workspace — exactly what self-signup at /register does
 * (owner membership + Pro trial). The password is asked for at a hidden prompt.
 */
final class CreateClientAccount extends Command
{
    protected $signature = 'engage:client:create {email?} {--name=} {--company=} {--timezone=Asia/Dubai} {--country=AE}';

    protected $description = 'Create a client portal user and workspace (same as signing up at /register).';

    public function handle(RegisterWorkspace $register): int
    {
        $input = [
            'email' => mb_strtolower(trim((string) ($this->argument('email') ?? text('Email', required: true)))),
            'name' => (string) ($this->option('name') ?? text('Full name', required: true)),
            'company_name' => (string) ($this->option('company') ?? text('Company / workspace name', required: true)),
            'timezone' => (string) $this->option('timezone'),
            'country' => strtoupper((string) $this->option('country')),
        ];
        $input['password'] = password('Password (min 10, mixed case, numbers)', required: true);

        $validator = Validator::make($input, [
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'name' => ['required', 'string', 'max:120'],
            'company_name' => ['required', 'string', 'max:120'],
            'timezone' => ['nullable', 'timezone:all'],
            'country' => ['nullable', 'string', 'size:2'],
            'password' => ['required', Password::min(10)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $result = $register($input);

        $this->components->info("Client {$input['email']} created with workspace \"{$result['tenant']->name}\" (Pro trial). Sign in on the client portal.");

        return self::SUCCESS;
    }
}
