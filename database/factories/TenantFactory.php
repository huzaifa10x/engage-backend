<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Tenants are RLS-protected: create them inside TenantContext::bypass() (see tests/TestCase).
 *
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'timezone' => 'Asia/Dubai',
            'locale' => 'en',
            'currency' => 'USD',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Suspended, 'suspended_at' => now()]);
    }
}
