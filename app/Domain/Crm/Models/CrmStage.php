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
 * @property string $group open | won | lost (reports count by group, whatever the stage is called)
 * @property ?Carbon $archived_at
 */
class CrmStage extends Model
{
    use BelongsToTenant, HasUuids;

    public const COLORS = ['grey', 'info', 'good', 'warn', 'bad', 'brand'];

    public const OPEN = 'open';

    public const WON = 'won';

    public const LOST = 'lost';

    public const GROUPS = [self::OPEN, self::WON, self::LOST];

    /** The default pipeline (batch 6, item 4): given to new workspaces, and offered to those with no stages yet. */
    public const SUGGESTED = [
        ['name' => 'New', 'color' => 'info', 'group' => self::OPEN],
        ['name' => 'Qualified', 'color' => 'warn', 'group' => self::OPEN],
        ['name' => 'Quote sent', 'color' => 'brand', 'group' => self::OPEN],
        ['name' => 'Booked', 'color' => 'info', 'group' => self::OPEN],
        ['name' => 'Won', 'color' => 'good', 'group' => self::WON],
        ['name' => 'Lost', 'color' => 'grey', 'group' => self::LOST],
    ];

    protected $table = 'crm_stages';

    protected $fillable = ['tenant_id', 'name', 'color', 'group', 'position', 'archived_at'];

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
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color, 'group' => $this->group ?? self::OPEN, 'archived' => $this->archived_at !== null];
    }
}
