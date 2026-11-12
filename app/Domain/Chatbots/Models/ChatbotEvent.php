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
 * @property string $kind enter | click | abandon | complete | handoff | error, and the run log: received | sent | note
 * @property ?string $detail the output taken, the reason, the outcome; for the run log the message id, send key or note id
 * @property int $seq global order of events (a run replays by it)
 */
class ChatbotEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** Events that record what was said, for Runs & Activity; never counted in insights. */
    public const LOG_KINDS = ['received', 'sent', 'note'];

    protected $fillable = ['tenant_id', 'flow_id', 'flow_version', 'session_id', 'node_id', 'kind', 'detail'];
}
