<?php

declare(strict_types=1);

namespace App\Infrastructure\Push;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Web Push without a third-party library: the payload is encrypted for one browser (RFC 8291,
 * aes128gcm) and the request is signed with the server's VAPID key (RFC 8292). Works with the push
 * services of Chrome, Edge, Firefox and Safari (iOS 16.4+ home-screen apps).
 */
final class WebPush
{
    /** DER prefix of a P-256 public key (SubjectPublicKeyInfo) before the 65-byte point. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(private readonly string $publicKey, private readonly string $privateKey, private readonly string $subject) {}

    public static function fromConfig(): ?self
    {
        $public = (string) config('engage.push.vapid_public_key');
        $private = (string) config('engage.push.vapid_private_key');

        return $public !== '' && $private !== '' ? new self($public, $private, (string) config('engage.push.subject')) : null;
    }

    /**
     * A new VAPID key pair, base64url: [public (65-byte point), private (32 bytes)].
     *
     * @return array{0: string, 1: string}
     */
    public static function generateKeys(): array
    {
        [$d, $point] = self::newKeyPair();

        return [self::b64($point), self::b64($d)];
    }

    /**
     * Sends one push. Returns the push service's HTTP status: 201 sent, 404/410 the subscription is
     * gone (delete it), 429/5xx try later.
     *
     * @param  'very-low'|'low'|'normal'|'high'  $urgency
     */
    public function send(string $endpoint, string $p256dh, string $auth, string $payload, int $ttl = 86400, string $urgency = 'high', ?string $topic = null): int
    {
        $body = self::encrypt($payload, self::unb64($p256dh), self::unb64($auth));
        $headers = [
            'Authorization' => 'vapid t='.$this->jwt($endpoint).', k='.$this->publicKey,
            'Content-Encoding' => 'aes128gcm',
            'Content-Type' => 'application/octet-stream',
            'TTL' => (string) $ttl,
            'Urgency' => $urgency,
        ];
        if ($topic !== null) {
            $headers['Topic'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $topic) ?? '', 0, 32);
        }

        return Http::withHeaders($headers)->withBody($body, 'application/octet-stream')->timeout(10)->post($endpoint)->status();
    }

    /**
     * RFC 8291: one record, keyed by an ECDH secret between a fresh key pair and the browser's key,
     * mixed with the browser's auth secret.
     *
     * @param  ?array{0: string, 1: string}  $serverKeys  fixed keys, for the RFC test vector only
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $salt = null, ?array $serverKeys = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Invalid push subscription keys.');
        }
        [$asPrivate, $asPublic] = $serverKeys ?? self::newKeyPair();
        $salt ??= random_bytes(16);

        $secret = self::ecdh($asPrivate, $asPublic, $uaPublic);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Push payload could not be encrypted.');
        }

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    /** The VAPID JWT (ES256) for the push service's origin, valid 12 hours. */
    private function jwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $input = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'
            .self::b64((string) json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $this->subject], JSON_UNESCAPED_SLASHES));

        $der = '';
        if (! openssl_sign($input, $der, self::privatePem(self::unb64($this->privateKey), self::unb64($this->publicKey)), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signature failed.');
        }

        return $input.'.'.self::b64(self::derToRaw($der));
    }

    /** @return array{0: string, 1: string} [32-byte private scalar, 65-byte public point] */
    private static function newKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = $key !== false ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;
        if (! is_array($ec)) {
            throw new RuntimeException('Could not create a P-256 key.');
        }

        return [str_pad($ec['d'], 32, "\0", STR_PAD_LEFT), "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)];
    }

    private static function ecdh(string $private, string $public, string $peer): string
    {
        $secret = openssl_pkey_derive(self::publicPem($peer), self::privatePem($private, $public), 32);
        if ($secret === false) {
            throw new RuntimeException('Push key agreement failed.');
        }

        return $secret;
    }

    public static function publicPem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** SEC1 ECPrivateKey for P-256. */
    public static function privatePem(string $d, string $point): string
    {
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$point;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** DER ECDSA signature → 64-byte r||s, as JWS requires. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $int = function () use ($der, &$offset): string {
            $len = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $len);
            $offset += 2 + $len;

            return str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
        };

        return $int().$int();
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }
}
