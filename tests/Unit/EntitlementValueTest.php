<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Plans\Entitlements\Entitlement;
use App\Domain\Plans\Enums\FeatureType;
use PHPUnit\Framework\TestCase;

final class EntitlementValueTest extends TestCase
{
    public function test_limit_semantics(): void
    {
        $seats = new Entitlement('team_seats', FeatureType::Limit, true, 5);
        $this->assertTrue($seats->permits(5));
        $this->assertFalse($seats->permits(6));

        $unlimited = new Entitlement('team_seats', FeatureType::Limit, true, null);
        $this->assertTrue($unlimited->isUnlimited());
        $this->assertTrue($unlimited->permits(PHP_INT_MAX));

        $disabled = new Entitlement('chatbots_flows', FeatureType::Limit, false, null);
        $this->assertFalse($disabled->permits(1));
        $this->assertFalse($disabled->isUnlimited());
    }
}
