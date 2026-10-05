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
 */
class ContactField extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'contact_fields';

    protected $fillable = ['tenant_id', 'key', 'label', 'type'];
}
