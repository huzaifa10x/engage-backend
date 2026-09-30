<?php

declare(strict_types=1);

namespace App\Infrastructure\Meta;

use RuntimeException;

/** A Graph API error with Meta's code / subcode / fbtrace_id preserved for support tickets. */
final class MetaApiException extends RuntimeException
{
    /** Codes Meta documents as temporary / throttling — safe to retry with backoff. */
    private const TRANSIENT = [1, 2, 4, 17, 32, 613, 80007, 130429, 131000, 131016, 131048, 131056, 133004];

    /** @param array<string, mixed> $error */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
        public readonly ?string $fbtraceId = null,
        public readonly array $error = [],
    ) {
        parent::__construct($message, $metaCode ?? $httpStatus);
    }

    /** @param array<string, mixed>|null $body */
    public static function fromResponse(int $status, ?array $body): self
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? "Graph API request failed ({$status}).");

        return new self(
            $message,
            $status,
            isset($error['code']) ? (int) $error['code'] : null,
            isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null,
            $error,
        );
    }

    public function isTransient(): bool
    {
        return $this->httpStatus >= 500 || in_array($this->metaCode, self::TRANSIENT, true);
    }

    /** OAuth errors: expired/revoked business token or missing permission. */
    public function isAuthError(): bool
    {
        return $this->metaCode === 190 || $this->metaCode === 10 || ($this->metaCode >= 200 && $this->metaCode <= 299);
    }

    /** (#100) Tried accessing nonexisting field — a field set this API version doesn't know. */
    public function isUnknownField(): bool
    {
        return $this->metaCode === 100 && str_contains(strtolower($this->getMessage()), 'nonexisting field');
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return ['http_status' => $this->httpStatus, 'code' => $this->metaCode, 'subcode' => $this->metaSubcode, 'fbtrace_id' => $this->fbtraceId];
    }
}
