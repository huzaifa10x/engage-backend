<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One customer's run through a flow.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property int $flow_version
 * @property string $conversation_id
 * @property string $contact_id
 * @property string $phone_number_id
 * @property string $status active | waiting | handed_off | completed | abandoned | stopped
 * @property ?string $current_node
 * @property bool $awaiting_input
 * @property int $retry_count
 * @property ?array<string, mixed> $vars
 * @property int $steps
 * @property ?Carbon $last_inbound_at
 * @property ?Carbon $last_activity_at
 * @property ?Carbon $reminder_sent_at
 * @property ?Carbon $resume_at
 * @property ?string $ended_reason
 * @property ?string $ended_node
 * @property ?Carbon $ended_at
 * @property ?Carbon $created_at
 */
class ChatbotSession extends Model
{
    use BelongsToTenant, HasUuids;

    public const RUNNING = ['active', 'waiting'];

    protected $attributes = ['status' => 'active', 'retry_count' => 0, 'steps' => 0, 'awaiting_input' => false];

    protected $fillable = [
        'tenant_id', 'flow_id', 'flow_version', 'conversation_id', 'contact_id', 'phone_number_id', 'status', 'current_node', 'awaiting_input',
        'retry_count', 'vars', 'steps', 'last_inbound_at', 'last_activity_at', 'reminder_sent_at', 'resume_at', 'ended_reason', 'ended_node', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'flow_version' => 'integer',
            'awaiting_input' => 'boolean',
            'retry_count' => 'integer',
            'vars' => 'array',
            'steps' => 'integer',
            'last_inbound_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'resume_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /** @return BelongsTo<ChatbotFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(ChatbotFlow::class, 'flow_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
