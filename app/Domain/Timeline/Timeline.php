<?php

declare(strict_types=1);

namespace App\Domain\Timeline;

use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the contact timeline. One call per thing that happened; never updates a row.
 *
 * Who caused it: the customer (their message, their tap, their form), an agent (a signed-in teammate,
 * named as they were at that moment) or the system (flows, campaigns, integrations, rules). Times are
 * UTC. The event belongs to the contact's current enquiry unless another is given.
 *
 * Recording never breaks what it records: a failure is logged and the action carries on.
 */
final class Timeline
{
    public const CUSTOMER = 'customer';

    public const AGENT = 'agent';

    public const SYSTEM = 'system';

    /**
     * @param  array<string, mixed>  $data  a short summary (never message text)
     * @param  'customer'|'agent'|'system'|null  $actor  null: an agent when a teammate is signed in, otherwise the system
     */
    public static function record(
        string $contactId,
        string $type,
        ?string $actor = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $data = [],
        DateTimeInterface|string|null $at = null,
        ?string $enquiryId = null,
        ?string $membershipId = null,
    ): void {
        try {
            $context = app(TenantContext::class);
            $tenantId = $context->tenantOrNull()?->id;
            if ($tenantId === null) {
                return;
            }
            $membershipId ??= $actor === null || $actor === self::AGENT ? $context->membership()?->id : null;
            $actor ??= $membershipId !== null ? self::AGENT : self::SYSTEM;
            $enquiryId ??= DB::table('contacts')->where('id', $contactId)->value('current_enquiry_id');

            DB::transaction(fn () => DB::table('timeline_events')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'enquiry_id' => $enquiryId,
                'type' => $type,
                'occurred_at' => ($at === null ? now() : Carbon::parse($at))->copy()->utc(),
                'actor' => $actor,
                'actor_membership_id' => $actor === self::AGENT ? $membershipId : null,
                'actor_name' => $actor === self::AGENT ? self::name($membershipId) : null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Timeline event was not recorded.', ['type' => $type, 'contact_id' => $contactId, 'error' => $e->getMessage()]);
        }
    }

    /** @var array<string, ?string> teammates' names, per process (they are copied onto each event) */
    private static array $names = [];

    private static function name(?string $membershipId): ?string
    {
        if ($membershipId === null) {
            return null;
        }
        if (! array_key_exists($membershipId, self::$names)) {
            if (count(self::$names) > 500) {
                self::$names = [];
            }
            $name = TenantMembership::query()->with('user')->find($membershipId)?->user?->name;
            self::$names[$membershipId] = $name !== null ? mb_substr((string) $name, 0, 120) : null;
        }

        return self::$names[$membershipId];
    }
}
