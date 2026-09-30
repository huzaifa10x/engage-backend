<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Exceptions\CrossTenantWriteDetected;
use App\Domain\Tenancy\Exceptions\TenantContextMissing;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\TenantMembership;
use Tests\TestCase;

final class TenantScopeTest extends TestCase
{
    public function test_querying_tenant_model_without_context_fails_closed(): void
    {
        $this->expectException(TenantContextMissing::class);

        TenantMembership::query()->get();
    }

    public function test_query_is_scoped_to_active_tenant(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $this->addMember($a);
        $this->addMember($b);

        $ids = $this->tenantContext()->run($a, fn () => TenantMembership::query()->pluck('tenant_id')->unique()->all());

        $this->assertSame([$a->id], array_values($ids));
    }

    public function test_writing_a_row_for_another_tenant_is_refused(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $owner = $this->addMember($a);

        $this->expectException(CrossTenantWriteDetected::class);

        $this->tenantContext()->run($a, fn () => Invitation::query()->create([
            'tenant_id' => $b->id,
            'email' => 'x@example.com',
            'role_id' => $owner->role_id,
            'token_hash' => hash('sha256', 'y'),
            'expires_at' => now()->addDay(),
        ]));
    }

    public function test_run_suspends_an_outer_bypass_and_restores_it(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $this->addMember($a);
        $this->addMember($b);

        $context = $this->tenantContext();

        $context->bypass(function () use ($context, $a) {
            $inner = $context->run($a, fn () => TenantMembership::query()->count());
            $this->assertSame(1, $inner);
            $this->assertTrue($context->isBypassing());
            $this->assertSame(2, TenantMembership::query()->count());
        });

        $this->assertFalse($context->isBypassing());
        $this->assertFalse($context->check());
    }
}
