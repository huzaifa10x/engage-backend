<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Models;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contact in one campaign. `status` covers what happens before WhatsApp is involved
 * (pending → queued, or skipped / failed with a reason); delivery state lives on the message.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property string $contact_id
 * @property ?string $message_id
 * @property string $status
 * @property ?string $reason
 * @property ?string $variant A | B (A/B test)
 * @property ?string $phase test | rest (A/B test on part of the list)
 * @property ?Carbon $not_before variant B of a send-time test waits until then
 */
class CampaignRecipient extends Model
{
    use BelongsToTenant, HasUuids;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['tenant_id', 'campaign_id', 'contact_id', 'message_id', 'status', 'reason', 'variant', 'phase', 'not_before'];

    protected function casts(): array
    {
        return ['not_before' => 'datetime'];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
