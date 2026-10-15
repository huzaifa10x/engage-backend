<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reusable piece of message text ("Opening hours", "Delivery policy"), inserted into any block.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $body
 * @property ?string $created_by_membership_id
 * @property ?Carbon $updated_at
 */
class ChatbotSnippet extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'body', 'created_by_membership_id'];
}
