<?php

declare(strict_types=1);

namespace App\Domain\Timeline;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One thing that happened to a contact. Added, never edited (the database refuses UPDATE as well).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property ?string $enquiry_id
 * @property string $type
 * @property Carbon $occurred_at
 * @property string $actor customer | agent | system
 * @property ?string $actor_membership_id
 * @property ?string $actor_name
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?array<string, mixed> $data
 * @property ?Carbon $created_at
 */
class TimelineEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** Types, grouped as the history's filters show them. */
    public const GROUPS = [
        'messages' => ['message.received', 'message.sent'],
        'enquiries' => ['enquiry.started', 'enquiry.source_confirmed', 'contact.created'],
        'tags' => ['tag.added', 'tag.removed'],
        'flows' => ['flow.started', 'flow.step', 'flow.answer', 'flow.ended', 'form.submitted'],
        'team' => ['conversation.assigned', 'conversation.closed', 'conversation.reopened'],
        'stages' => ['stage.changed'],
        'consent' => ['consent.changed'],
        'bookings' => ['booking.created', 'booking.changed', 'booking.cancelled'],
        'payments' => ['payment.paid', 'payment.refunded'],
        'integrations' => ['crm.synced', 'webhook.delivered', 'webhook.failed', 'feedback.answered'],
    ];

    protected $table = 'timeline_events';

    protected $fillable = ['tenant_id', 'contact_id', 'enquiry_id', 'type', 'occurred_at', 'actor', 'actor_membership_id', 'actor_name', 'subject_type', 'subject_id', 'data'];

    /**
     * Message lines whose text the retention setting removed are marked data.removed = true (item 14):
     * the line stays on the timeline, the text is gone.
     *
     * @param  iterable<TimelineEvent>  $events
     */
    public static function markRemovedMessages(iterable $events): void
    {
        $ids = [];
        foreach ($events as $e) {
            if (in_array($e->type, ['message.received', 'message.sent'], true) && $e->subject_id !== null) {
                $ids[] = $e->subject_id;
            }
        }
        $removed = $ids === [] ? [] : array_flip(DB::table('messages')->whereIn('id', $ids)->whereNotNull('redacted_at')->pluck('id')->all());
        foreach ($events as $e) {
            if ($e->subject_id !== null && isset($removed[$e->subject_id])) {
                $e->setAttribute('data', ['removed' => true] + ($e->data ?? []));
            }
        }
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'data' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Timeline events are never edited; record a correction as a new event.'));
    }
}
