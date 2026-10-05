<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved rule over contacts. Never a stored list: who is "in" the segment is computed on read.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $match
 * @property list<array{field: string, op: string, value?: mixed}> $rules
 * @property ?string $created_by_membership_id
 */
class Segment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $attributes = ['match' => 'all'];

    protected $fillable = ['tenant_id', 'name', 'match', 'rules', 'created_by_membership_id'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
