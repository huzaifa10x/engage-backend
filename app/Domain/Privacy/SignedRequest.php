<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use InvalidArgumentException;

/** Facebook `signed_request`: "<base64url HMAC-SHA256 signature>.<base64url JSON payload>". */
final class SignedRequest
{
    /** @return array<string, mixed> */
    public static function parse(string $signedRequest, string $appSecret): array
    {
        $parts = explode('.', $signedRequest, 2);
        if (count($parts) !== 2 || $appSecret === '') {
            throw new InvalidArgumentException('Malformed signed_request.');
        }

        [$signature, $payload] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $appSecret, true));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('Invalid signed_request signature.');
        }

        $data = json_decode((string) self::base64UrlDecode($payload), true);
        if (! is_array($data) || strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256' || ! isset($data['user_id'])) {
            throw new InvalidArgumentException('Invalid signed_request payload.');
        }

        return $data;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
