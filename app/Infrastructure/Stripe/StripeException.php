<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** Stripe refused a request, could not be reached, or billing is not configured. */
final class StripeException extends DomainException
{
    public function __construct(string $message, public readonly int $httpStatus = 502, public readonly ?string $stripeCode = null)
    {
        parent::__construct($message, array_filter(['stripe_code' => $stripeCode]));
    }

    public static function notConfigured(): self
    {
        return new self('Online billing is not set up yet. Please contact support to change your plan.', 503);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::BillingError;
    }

    public function status(): int
    {
        // Card / request problems are the client's to fix (4xx); everything else is upstream.
        return $this->httpStatus >= 400 && $this->httpStatus < 500 && $this->httpStatus !== 401 ? 422 : ($this->httpStatus === 503 ? 503 : 502);
    }
}
