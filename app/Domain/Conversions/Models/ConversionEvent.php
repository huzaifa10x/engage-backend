<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One conversion found, and what happened to it: queued → sent, or failed / expired / skipped with
 * the reason in plain words.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $conversation_id
 * @property ?string $contact_id
 * @property ?string $ad_click_id
 * @property ?string $rule_id
 * @property ?string $waba_id
 * @property string $event_name
 * @property Carbon $event_time
 * @property ?string $value
 * @property ?string $currency
 * @property ?string $order_id
 * @property string $status
 * @property int $attempts
 * @property ?Carbon $next_attempt_at
 * @property ?string $error_reason
 * @property ?array<string, mixed> $meta_response
 * @property ?Carbon $sent_at
 * @property bool $is_test
 */
class ConversionEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const SKIPPED = 'skipped';

    /** Why an event was skipped, in plain words (the events log shows these). */
    public const SKIP_REASONS = [
        'no_click_id' => 'The chat did not start from a Meta ad (no click ID), so Meta cannot match it.',
        'test_run' => 'A test run of a flow: test runs never report conversions.',
        'missing_permission' => 'The WhatsApp account has not given 10X Engage permission to send conversion events. Reconnect it.',
        'reporting_off' => 'Conversion reporting is off for this WhatsApp account.',
    ];

    protected $fillable = [
        'tenant_id', 'conversation_id', 'contact_id', 'ad_click_id', 'rule_id', 'waba_id', 'event_name', 'event_time', 'value', 'currency',
        'order_id', 'status', 'attempts', 'next_attempt_at', 'error_reason', 'meta_response', 'sent_at', 'is_test',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime',
            'meta_response' => 'array', 'is_test' => 'boolean', 'attempts' => 'integer', 'value' => 'decimal:2',
        ];
    }
}
