<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Funnel log: a session entered a node, left it by a given output, or stopped there.
 *
 * @property string $id
 * @property string $flow_id
 * @property int $flow_version
 * @property string $session_id
 * @property string $node_id
 * @property string $kind enter | click | abandon | complete | handoff | error
 * @property ?string $detail
 */
class ChatbotEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'flow_id', 'flow_version', 'session_id', 'node_id', 'kind', 'detail'];
}
