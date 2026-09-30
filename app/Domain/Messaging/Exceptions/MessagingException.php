<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** Business-rule refusals when sending (window closed, opted out, number unavailable, ...). */
final class MessagingException extends DomainException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, private readonly ErrorCode $error, private readonly int $httpStatus = 422, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function windowClosed(): self
    {
        return new self('The 24-hour customer service window has closed. Send an approved template to restart the conversation.', ErrorCode::WindowClosed, 422);
    }

    public static function optedOut(): self
    {
        return new self('This contact has opted out of messages. They must send START (or opt in again) before you can message them.', ErrorCode::ContactOptedOut, 422);
    }

    public static function numberUnavailable(): self
    {
        return new self('This WhatsApp number is not connected. Reconnect it from Channels.', ErrorCode::NumberUnavailable, 409);
    }

    public static function mediaNotReady(): self
    {
        return new self('The attachment is not available.', ErrorCode::ValidationFailed, 422);
    }

    public static function noRecipient(): self
    {
        return new self('This contact has no WhatsApp phone number or user ID to message.', ErrorCode::ValidationFailed, 422);
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
