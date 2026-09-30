<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only: never updated or deleted by application code. */
class QualityEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'waba_account_id', 'phone_number_id', 'event_type', 'old_value', 'new_value', 'payload', 'occurred_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }
}
