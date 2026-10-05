<?php

declare(strict_types=1);

namespace App\Domain\Plans\Models;

use App\Domain\Plans\Enums\PlanVersionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $key
 * @property string $name
 */
class Plan extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'name', 'description', 'is_public', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public static function byKey(string $key): self
    {
        return static::query()->where('key', $key)->firstOrFail();
    }

    /** @return HasMany<PlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }

    public function activeVersion(): ?PlanVersion
    {
        return $this->versions()->where('status', PlanVersionStatus::Active)->orderByDesc('version')->first();
    }

    public function activeVersionOrFail(): PlanVersion
    {
        return $this->activeVersion() ?? throw new \RuntimeException("Plan [{$this->key}] has no active version. Run the PlanCatalogSeeder.");
    }
}
