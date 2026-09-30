<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Secrets\DatabaseSecretStore;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Console\Command;

/**
 *   engage:secrets:key            print a new key entry to prepend to ENGAGE_SECRETS_KEYS
 *   engage:secrets:key --rotate   re-encrypt all secrets with the first (current) key
 */
final class SecretsKey extends Command
{
    protected $signature = 'engage:secrets:key {--id= : Key id, e.g. k2} {--rotate : Re-encrypt every secret with the current key}';

    protected $description = 'Generate a secrets key, or rotate stored secrets onto the current key.';

    public function handle(SecretStore $store): int
    {
        if ($this->option('rotate')) {
            if (! $store instanceof DatabaseSecretStore) {
                $this->components->error('Rotation is only supported by the database secret store.');

                return self::FAILURE;
            }
            $this->components->info('Re-encrypted '.$store->rotateAll().' secret(s).');

            return self::SUCCESS;
        }

        $id = (string) ($this->option('id') ?: 'k'.now()->format('ymd'));
        $this->line($id.':base64:'.base64_encode(random_bytes(32)));
        $this->components->info('Prepend this entry to ENGAGE_SECRETS_KEYS (comma-separated), deploy, then run --rotate.');

        return self::SUCCESS;
    }
}
