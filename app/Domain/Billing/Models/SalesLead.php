<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A demo or contact request sent from the public website.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property ?string $company
 * @property ?string $phone
 * @property ?string $team_size
 * @property string $topic
 * @property ?string $message
 * @property ?string $source
 * @property string $status
 * @property ?Carbon $handled_at
 * @property ?Carbon $created_at
 * @property ?string $admin_note
 */
class SalesLead extends Model
{
    use HasUuids;

    /** "spam": kept, but caught by the form's bot trap; no email is sent for it. */
    public const STATUSES = ['new', 'contacted', 'closed', 'spam'];

    protected $fillable = ['name', 'email', 'company', 'phone', 'team_size', 'topic', 'message', 'source', 'ip_address', 'status', 'admin_note', 'handled_by_admin_id', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
