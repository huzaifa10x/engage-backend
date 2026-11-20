<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What happens next with a customer (3.3, B1): call back, send the quote, a meeting. On an enquiry,
 * or on the contact for service chats. Done or cancelled, never deleted.
 *
 * @property string $id
 * @property string $contact_id
 * @property ?string $enquiry_id
 * @property string $type
 * @property string $title
 * @property Carbon $due_at
 * @property ?string $assigned_membership_id
 * @property ?string $created_by_membership_id
 * @property ?Carbon $done_at
 * @property ?string $done_by_membership_id
 * @property ?Carbon $cancelled_at
 * @property ?string $cancel_reason
 */
class NextStep extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPES = ['call' => 'Call', 'message' => 'Follow up', 'meeting' => 'Schedule meeting', 'quote' => 'Send quote', 'other' => 'Other'];

    protected $fillable = ['tenant_id', 'contact_id', 'enquiry_id', 'type', 'title', 'due_at', 'assigned_membership_id', 'created_by_membership_id',
        'done_at', 'done_by_membership_id', 'cancelled_at', 'cancel_reason'];

    /**
     * @param  Builder<NextStep>  $query
     * @return Builder<NextStep>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at')->whereNull('cancelled_at');
    }

    public function isOpen(): bool
    {
        return $this->done_at === null && $this->cancelled_at === null;
    }

    /** @param array<string, string> $names membership id → name */
    public function summary(array $names = []): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'enquiry_id' => $this->enquiry_id,
            'type' => $this->type,
            'type_label' => self::TYPES[$this->type] ?? 'Other',
            'title' => $this->title,
            'due_at' => $this->due_at->toIso8601String(),
            'overdue' => $this->isOpen() && $this->due_at->isPast(),
            'assigned' => $this->assigned_membership_id !== null ? ['id' => $this->assigned_membership_id, 'name' => $names[$this->assigned_membership_id] ?? 'Former teammate'] : null,
            'status' => $this->done_at !== null ? 'done' : ($this->cancelled_at !== null ? 'cancelled' : 'open'),
            'done_at' => $this->done_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
