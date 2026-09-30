<?php

declare(strict_types=1);

namespace App\Domain\Plans\Models;

use App\Domain\Plans\Enums\FeatureType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key
 * @property FeatureType $type
 */
class Feature extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'name', 'type', 'unit', 'description'];

    protected function casts(): array
    {
        return ['type' => FeatureType::class];
    }
}
