<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One saved draft of a flow (the last 50 are kept): what conflict-safe editing compares against
 * and what "Restore this draft" brings back.
 *
 * @property string $id
 * @property string $flow_id
 * @property int $revision
 * @property string $name
 * @property array<string, mixed> $draft
 * @property ?array<string, mixed> $settings
 * @property ?string $membership_id
 * @property ?Carbon $created_at
 */
class ChatbotDraftRevision extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const KEEP = 50;

    protected $fillable = ['tenant_id', 'flow_id', 'revision', 'name', 'draft', 'settings', 'membership_id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'draft' => 'array', 'settings' => 'array', 'created_at' => 'datetime'];
    }
}
