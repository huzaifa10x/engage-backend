<?php

declare(strict_types=1);

namespace App\Infrastructure\Secrets;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * ENGAGE_SECRETS_KEYS="k2:base64:...,k1:base64:..." — first key encrypts, all keys decrypt.
 * Without a configured keyring (local dev only) it falls back to APP_KEY under key id "app".
 */
final class Keyring
{
    /** @var array<string, Encrypter> */
    private array $encrypters = [];

    private string $current;

    public function __construct(?string $config, string $appKey, bool $production)
    {
        $config = trim((string) $config);

        if ($config === '') {
            if ($production) {
                throw new RuntimeException('ENGAGE_SECRETS_KEYS must be set in production.');
            }
            $this->current = 'app';
            $this->encrypters['app'] = new Encrypter($this->decode($appKey), 'aes-256-gcm');

            return;
        }

        foreach (explode(',', $config) as $i => $entry) {
            [$id, $key] = array_pad(explode(':', trim($entry), 2), 2, '');
            if (preg_match('/^[a-z0-9_-]{1,16}$/i', $id) !== 1 || $key === '') {
                throw new RuntimeException('Malformed ENGAGE_SECRETS_KEYS entry #'.($i + 1).'.');
            }
            $this->encrypters[$id] = new Encrypter($this->decode($key), 'aes-256-gcm');
            $this->current ??= $id;
        }
    }

    public function currentId(): string
    {
        return $this->current;
    }

    public function encrypter(?string $id = null): Encrypter
    {
        return $this->encrypters[$id ?? $this->current] ?? throw new RuntimeException("Secret key [{$id}] is not in the keyring.");
    }

    private function decode(string $key): string
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if (! is_string($raw) || strlen($raw) !== 32) {
            throw new RuntimeException('Secret keys must be 32 bytes (base64:...).');
        }

        return $raw;
    }
}
