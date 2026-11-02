<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pipeline stage the workspace defined (e.g. New, Contacted, Qualified, Booked, Won, Lost).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $color
 * @property int $position
 * @property ?Carbon $archived_at
 */
class CrmStage extends Model
{
    use BelongsToTenant, HasUuids;

    public const COLORS = ['grey', 'info', 'good', 'warn', 'bad', 'brand'];

    /** Offered when a workspace has no stages yet ("Use suggested stages"). */
    public const SUGGESTED = [
        ['name' => 'New', 'color' => 'info'],
        ['name' => 'Contacted', 'color' => 'brand'],
        ['name' => 'Qualified', 'color' => 'warn'],
        ['name' => 'Won', 'color' => 'good'],
        ['name' => 'Lost', 'color' => 'grey'],
    ];

    protected $table = 'crm_stages';

    protected $fillable = ['tenant_id', 'name', 'color', 'position', 'archived_at'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'archived_at' => 'datetime'];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return array{id: string, name: string, color: string, archived: bool} */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color, 'archived' => $this->archived_at !== null];
    }
}
