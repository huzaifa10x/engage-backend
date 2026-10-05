<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Append-only consent history (who / how / when) — evidence for Meta and privacy requests. */
/**
 * Append-only consent ledger entry: never updated or deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string $action
 * @property string $source
 * @property ?string $detail
 * @property ?string $message_id
 * @property ?string $membership_id
 * @property ?Carbon $created_at
 * @property-read ?Contact $contact
 */
class ConsentEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'contact_id', 'action', 'source', 'detail', 'message_id', 'membership_id'];

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
