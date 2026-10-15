<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A teammate's comment on one block of a flow (review notes, questions); resolvable.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property string $node_id
 * @property ?string $membership_id
 * @property string $body
 * @property ?Carbon $resolved_at
 * @property ?string $resolved_by_membership_id
 * @property ?Carbon $created_at
 */
class ChatbotNodeComment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'flow_id', 'node_id', 'membership_id', 'body', 'resolved_at', 'resolved_by_membership_id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
