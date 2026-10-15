<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A published snapshot. Sessions run on the version they started with, so publishing a change
 * never moves a customer who is in the middle of a conversation onto a node that no longer exists.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property int $version
 * @property array<string, mixed> $graph
 * @property ?array<string, mixed> $settings
 * @property ?string $published_by_membership_id
 * @property ?Carbon $created_at
 */
class ChatbotFlowVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'flow_id', 'version', 'graph', 'settings', 'published_by_membership_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'graph' => 'array', 'settings' => 'array', 'created_at' => 'datetime'];
    }
}
