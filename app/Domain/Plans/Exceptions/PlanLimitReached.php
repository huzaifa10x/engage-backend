<?php

declare(strict_types=1);

namespace App\Domain\Plans\Exceptions;

use App\Domain\Plans\FeatureKey;
use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** 402 so the client can render an upgrade prompt distinct from a permission error. */
final class PlanLimitReached extends DomainException
{
    public function __construct(FeatureKey $feature, int $limit, int $current)
    {
        parent::__construct("You have reached your plan limit for {$feature->label()}.", [
            'feature' => $feature->value,
            'limit' => $limit,
            'current' => $current,
        ]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PlanLimitReached;
    }

    public function status(): int
    {
        return 402;
    }
}
