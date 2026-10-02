<?php

declare(strict_types=1);

namespace App\Domain\Templates\Exceptions;

use App\Infrastructure\Meta\MetaApiException;
use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;
use App\Support\Meta\MetaErrorCatalog;

/** User-facing template errors (Meta rejected the submission, template not editable, ...). */
final class TemplateException extends DomainException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, private readonly ErrorCode $error, private readonly int $httpStatus = 422, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** Meta refused a create / edit / delete: pass its explanation on to the client. */
    public static function meta(MetaApiException $e, string $action): self
    {
        $hint = MetaErrorCatalog::hint($e->metaCode, $e->metaSubcode);
        $message = "Meta could not {$action} this template: ".rtrim($e->getMessage(), '.').'.'.($hint !== null ? ' '.$hint : '');

        return new self($message, ErrorCode::MetaApiError, $e->isTransient() ? 502 : 422, array_filter($e->context(), fn ($v) => $v !== null));
    }

    public static function notEditable(string $status): self
    {
        return new self("A template that is {$status} cannot be edited. Only approved, rejected or paused templates can be changed.", ErrorCode::Conflict, 409);
    }

    public static function accountUnavailable(): self
    {
        return new self('This WhatsApp Business Account is not connected. Reconnect it from Channels.', ErrorCode::NumberUnavailable, 409);
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
