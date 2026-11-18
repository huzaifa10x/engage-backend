<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One stage change on a contact. Append-only: never updated or deleted by the application.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property ?string $from_stage_id
 * @property ?string $to_stage_id
 * @property ?string $changed_by_membership_id
 * @property string $source user | api | import | automation | flow | booking | payment | lead_rule
 * @property ?string $enquiry_id
 * @property ?int $version the enquiry's stage version after this change
 * @property ?string $reason lost reason, Won approval reference, or what moved it
 * @property Carbon $changed_at
 */
class ContactStageEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $table = 'contact_stage_events';

    protected $fillable = ['tenant_id', 'contact_id', 'enquiry_id', 'from_stage_id', 'to_stage_id', 'changed_by_membership_id', 'source', 'version', 'reason', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
