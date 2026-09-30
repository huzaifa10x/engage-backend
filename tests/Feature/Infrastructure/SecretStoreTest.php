<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\Secrets\DatabaseSecretStore;
use App\Infrastructure\Secrets\Keyring;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecretStoreTest extends TestCase
{
    private function useKeys(string $keys): DatabaseSecretStore
    {
        $this->app->instance(Keyring::class, new Keyring($keys, (string) config('app.key'), false));
        $this->app->forgetInstance(SecretStore::class);

        /** @var DatabaseSecretStore */
        return $this->app->make(SecretStore::class);
    }

    public function test_secrets_are_encrypted_and_survive_key_rotation(): void
    {
        $k1 = 'k1:base64:'.base64_encode(random_bytes(32));
        $k2 = 'k2:base64:'.base64_encode(random_bytes(32));

        $id = $this->useKeys($k1)->put('meta.business_token', 'EAA-very-secret');
        $row = $this->tenantContext()->bypass(fn () => DB::table('secrets')->find($id));
        $this->assertSame('k1', $row->key_id);
        $this->assertStringNotContainsString('EAA-very-secret', $row->ciphertext);

        $store = $this->useKeys("{$k2},{$k1}");
        $this->assertSame('EAA-very-secret', $store->get($id), 'old key still decrypts');
        $this->assertSame(1, $store->rotateAll());
        $this->assertSame('k2', $this->tenantContext()->bypass(fn () => DB::table('secrets')->find($id))->key_id);

        $this->assertSame('EAA-very-secret', $this->useKeys($k2)->get($id), 'k1 no longer needed');

        $store->destroy($id);
        $this->expectException(\RuntimeException::class);
        $store->get($id);
    }
}
