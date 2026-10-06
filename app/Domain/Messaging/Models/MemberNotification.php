<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An in-app notification for one member (the bell in the header).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string $type
 * @property string $title
 * @property ?string $body
 * @property ?string $url
 * @property ?Carbon $read_at
 * @property ?Carbon $created_at
 */
class MemberNotification extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'membership_id', 'type', 'title', 'body', 'url', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
