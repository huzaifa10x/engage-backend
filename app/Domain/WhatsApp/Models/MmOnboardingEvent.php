<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of Marketing Messages status changes: never updated or deleted by application code. */
class MmOnboardingEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'waba_account_id', 'from_status', 'to_status', 'trigger', 'raw_payload'];

    protected function casts(): array
    {
        return ['raw_payload' => 'array'];
    }
}
