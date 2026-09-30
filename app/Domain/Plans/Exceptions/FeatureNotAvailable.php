<?php

declare(strict_types=1);

namespace App\Domain\Plans\Exceptions;

use App\Domain\Plans\FeatureKey;
use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

final class FeatureNotAvailable extends DomainException
{
    public function __construct(FeatureKey $feature, string $planKey)
    {
        parent::__construct("{$feature->label()} is not included in your current plan.", [
            'feature' => $feature->value,
            'plan' => $planKey,
        ]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::FeatureNotAvailable;
    }

    public function status(): int
    {
        return 403;
    }
}
