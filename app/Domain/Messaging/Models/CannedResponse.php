<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A saved reply the team inserts with "/shortcut".
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shortcut
 * @property string $body
 * @property ?string $created_by_membership_id
 * @property ?Carbon $created_at
 */
class CannedResponse extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'shortcut', 'body', 'created_by_membership_id'];
}
