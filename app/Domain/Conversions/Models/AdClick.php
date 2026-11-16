<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Click-to-WhatsApp ad click (the referral on the incoming message it started).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $contact_id
 * @property ?string $message_id
 * @property string $waba_id
 * @property ?string $ctwa_clid
 * @property ?string $source_id
 * @property ?string $source_type
 * @property ?string $source_url
 * @property ?string $headline
 * @property Carbon $clicked_at
 */
class AdClick extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'conversation_id', 'contact_id', 'message_id', 'waba_id', 'ctwa_clid', 'source_id', 'source_type', 'source_url', 'headline', 'clicked_at'];

    protected function casts(): array
    {
        return ['clicked_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
