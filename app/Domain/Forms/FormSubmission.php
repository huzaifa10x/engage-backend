<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string $form_version_id
 * @property string $contact_id
 * @property ?string $conversation_id
 * @property string $message_id
 * @property ?string $session_id
 * @property array<string, mixed> $answers
 * @property list<array{label: string, value: string, saved_to: ?string}> $saved
 * @property ?bool $consent
 * @property ?Carbon $created_at
 */
class FormSubmission extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'form_id', 'form_version_id', 'contact_id', 'conversation_id', 'message_id', 'session_id', 'answers', 'saved', 'consent'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'saved' => 'array', 'consent' => 'boolean'];
    }
}
