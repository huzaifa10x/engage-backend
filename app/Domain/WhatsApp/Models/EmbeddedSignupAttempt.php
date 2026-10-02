<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Enums\SignupStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Audit of one Embedded Signup attempt — invaluable when a signup half-completes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $initiated_by_user_id
 * @property string $flow
 * @property SignupStatus $status
 * @property ?string $event
 * @property ?string $waba_id
 * @property ?string $phone_number_id
 * @property ?string $meta_business_id
 * @property ?string $waba_account_id
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?array<string, mixed> $steps
 * @property ?array<string, mixed> $session_payload
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 */
class EmbeddedSignupAttempt extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'initiated_by_user_id', 'flow', 'status', 'event', 'current_step', 'meta_session_id', 'meta_user_id',
        'waba_id', 'phone_number_id', 'meta_business_id', 'waba_account_id', 'error_code', 'error_message', 'steps',
        'session_payload', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SignupStatus::class,
            'steps' => 'array',
            'session_payload' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function markStep(string $step, string $state, ?string $error = null): void
    {
        $steps = $this->steps ?? [];
        $steps[$step] = array_filter(['state' => $state, 'at' => now()->toIso8601String(), 'error' => $error]);
        $this->steps = $steps;
        $this->save();
    }

    public function stepDone(string $step): bool
    {
        return ($this->steps[$step]['state'] ?? null) === 'done';
    }

    public function fail(string $message, ?string $code = null): void
    {
        $this->forceFill(['status' => SignupStatus::Failed, 'error_message' => $message, 'error_code' => $code, 'finished_at' => now()])->save();
    }
}
