<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** User-facing onboarding / channel errors with a stable error code. */
final class WhatsappException extends DomainException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, private readonly ErrorCode $error, private readonly int $httpStatus = 422, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('WhatsApp onboarding is not configured on this environment.', ErrorCode::WhatsappNotConfigured, 503);
    }

    public static function wabaOwnedByAnotherWorkspace(): self
    {
        return new self('This WhatsApp Business Account is already connected to another 10X Engage workspace.', ErrorCode::WabaAlreadyConnected, 409);
    }

    public static function signupInvalid(string $message = 'This signup session has expired. Start again from Connect WhatsApp.'): self
    {
        return new self($message, ErrorCode::SignupSessionInvalid, 410);
    }

    public static function coexistenceUnavailable(): self
    {
        return new self('Connecting an existing WhatsApp Business app number is not available on this workspace yet.', ErrorCode::FeatureNotAvailable, 403);
    }

    public static function meta(string $message, ?int $code, ?string $fbtrace): self
    {
        return new self($message, ErrorCode::MetaApiError, 502, array_filter(['meta_code' => $code, 'fbtrace_id' => $fbtrace]));
    }

    public function errorCode(): ErrorCode
    {
        return $this->error;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->extra;
    }
}
