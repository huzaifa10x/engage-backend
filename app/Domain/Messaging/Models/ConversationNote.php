<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An internal note on a conversation: visible to the team, never sent to the customer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property ?string $membership_id
 * @property string $body
 * @property list<string>|null $mentions
 * @property ?Carbon $created_at
 */
class ConversationNote extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'conversation_id', 'membership_id', 'body', 'mentions'];

    protected function casts(): array
    {
        return ['mentions' => 'array'];
    }
}
