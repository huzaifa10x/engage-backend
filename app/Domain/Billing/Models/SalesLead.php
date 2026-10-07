<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
 */
class SalesLead extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'email', 'company', 'phone', 'team_size', 'topic', 'message', 'source', 'ip_address'];
}
