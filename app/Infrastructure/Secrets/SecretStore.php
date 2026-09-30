<?php

declare(strict_types=1);

namespace App\Infrastructure\Secrets;

/**
 * Stores credentials (Meta business tokens, registration PINs) outside the rows that use them.
 * Callers keep only the returned id. Swap the implementation for AWS KMS / Vault later without
 * touching domain code.
 */
interface SecretStore
{
    public function put(string $purpose, string $plaintext, ?string $tenantId = null): string;

    public function get(string $id): string;

    public function replace(string $id, string $plaintext): void;

    public function destroy(string $id): void;
}
