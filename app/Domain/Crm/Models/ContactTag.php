<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 */
class ContactTag extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'contact_tags';

    protected $fillable = ['tenant_id', 'name'];
}
