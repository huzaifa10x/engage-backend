<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A signed chat-to-order token (?xl=…) put on a store link sent from a chat.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $contact_id
 * @property ?string $message_id
 * @property string $sent_by
 * @property ?string $sent_by_membership_id
 * @property string $url
 * @property ?array<string, mixed> $conversation_source
 * @property Carbon $expires_at
 * @property ?string $used_order_id
 * @property ?Carbon $used_at
 * @property ?Carbon $created_at
 */
class CommerceLinkToken extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['digest'];

    protected function casts(): array
    {
        return ['conversation_source' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
