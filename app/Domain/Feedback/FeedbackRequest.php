<?php

declare(strict_types=1);

namespace App\Domain\Feedback;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property ?string $conversation_id
 * @property ?string $phone_number_id
 * @property string $kind csat | review
 * @property string $source conversation_closed | order_delivered | booking_completed
 * @property string $reference
 * @property ?string $agent_membership_id
 * @property ?string $message_id
 * @property string $status sent | answered | skipped | failed
 * @property ?string $reason
 * @property ?string $rating good | ok | poor
 * @property ?Carbon $answered_at
 * @property ?Carbon $created_at
 */
class FeedbackRequest extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const RATINGS = ['good' => 'Good', 'ok' => 'OK', 'poor' => 'Poor'];

    protected $fillable = ['tenant_id', 'contact_id', 'conversation_id', 'phone_number_id', 'kind', 'source', 'reference', 'agent_membership_id',
        'message_id', 'status', 'reason', 'rating', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }
}
