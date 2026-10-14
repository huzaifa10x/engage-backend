<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property ?list<string> $options
 * @property ?array<string, mixed> $rules
 */
class ContactField extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'contact_fields';

    protected $fillable = ['tenant_id', 'key', 'label', 'type', 'options', 'rules'];

    protected function casts(): array
    {
        return ['options' => 'array', 'rules' => 'array'];
    }
}
