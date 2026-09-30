<?php

declare(strict_types=1);

namespace App\Infrastructure\Secrets;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** AES-256-GCM ciphertext in the `secrets` table (RLS: platform bypass only). */
final class DatabaseSecretStore implements SecretStore
{
    public function __construct(
        private readonly Keyring $keyring,
        private readonly TenantContext $context,
    ) {}

    public function put(string $purpose, string $plaintext, ?string $tenantId = null): string
    {
        $id = (string) Str::uuid7();

        $this->context->bypass(fn () => DB::table('secrets')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'purpose' => $purpose,
            'key_id' => $this->keyring->currentId(),
            'ciphertext' => $this->keyring->encrypter()->encryptString($plaintext),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $id;
    }

    public function get(string $id): string
    {
        $row = $this->context->bypass(fn () => DB::table('secrets')->where('id', $id)->whereNull('destroyed_at')->first(['key_id', 'ciphertext']));

        if ($row === null) {
            throw new RuntimeException("Secret [{$id}] not found or destroyed.");
        }

        return $this->keyring->encrypter($row->key_id)->decryptString($row->ciphertext);
    }

    public function replace(string $id, string $plaintext): void
    {
        $this->context->bypass(fn () => DB::table('secrets')->where('id', $id)->update([
            'key_id' => $this->keyring->currentId(),
            'ciphertext' => $this->keyring->encrypter()->encryptString($plaintext),
            'rotated_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** Crypto-shred: the row stays for audit, the ciphertext is gone. */
    public function destroy(string $id): void
    {
        $this->context->bypass(fn () => DB::table('secrets')->where('id', $id)->update([
            'ciphertext' => '',
            'destroyed_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** Re-encrypt every live secret not yet under the current key. Returns rows rotated. */
    public function rotateAll(): int
    {
        $current = $this->keyring->currentId();
        $rotated = 0;

        $this->context->bypass(function () use ($current, &$rotated) {
            DB::table('secrets')->whereNull('destroyed_at')->where('key_id', '!=', $current)
                ->orderBy('id')->chunkById(200, function ($rows) use (&$rotated) {
                    foreach ($rows as $row) {
                        $this->replace($row->id, $this->keyring->encrypter($row->key_id)->decryptString($row->ciphertext));
                        $rotated++;
                    }
                });
        });

        return $rotated;
    }
}
