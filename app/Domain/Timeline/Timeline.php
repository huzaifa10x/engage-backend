<?php

declare(strict_types=1);

namespace App\Domain\Timeline;

use App\Domain\Developer\Services\TimelineWebhooks;
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

    public const BULK_UPDATE = 'bulk update';

    public const IMPORT = 'import';

    public const TAG_DELETED = 'tag deleted';

    /** @var ?array{how: string, by: ?string} set while a bulk change runs (see bulk()) */
    private static ?array $bulk = null;

    /** @var ?array{membership: ?string, bulk: ?array{how: string, by: ?string}, at: string} set while a captured event is applied */
    private static ?array $replaying = null;

    /**
     * Who and when, at the moment something happens: kept with a captured event so it is recorded
     * in the background exactly as if it had been recorded then.
     *
     * @return array{membership: ?string, bulk: ?array{how: string, by: ?string}, at: string}
     */
    public static function moment(): array
    {
        return ['membership' => app(TenantContext::class)->membership()?->id, 'bulk' => self::$bulk, 'at' => now()->utc()->format('Y-m-d\TH:i:s.uP')];
    }

    /**
     * Records with a captured moment (see moment()).
     *
     * @param  array{membership: ?string, bulk: ?array{how: string, by: ?string}, at: string}  $moment
     */
    public static function replay(array $moment, callable $fn): void
    {
        $outer = self::$replaying;
        self::$replaying = $moment;
        try {
            $fn();
        } finally {
            self::$replaying = $outer;
        }
    }

    /**
     * Runs a change made to many contacts at once (table selection, CSV import, deleting a tag).
     * Each contact still gets its own event, marked with how and by whom, so its history reads
     * "Tag “VIP” added in a bulk update by Sara".
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function bulk(string $how, callable $fn): mixed
    {
        $outer = self::$bulk;
        $membershipId = app(TenantContext::class)->membership()?->id;
        self::$bulk = ['how' => $how, 'by' => self::name($membershipId)];
        try {
            return $fn();
        } finally {
            self::$bulk = $outer;
        }
    }

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
            $current = self::$replaying !== null ? self::$replaying['membership'] : $context->membership()?->id;
            $membershipId ??= $actor === null || $actor === self::AGENT ? $current : null;
            $at ??= self::$replaying['at'] ?? null;
            $actor ??= $membershipId !== null ? self::AGENT : self::SYSTEM;
            $enquiryId ??= DB::table('contacts')->where('id', $contactId)->value('current_enquiry_id');
            $bulk = self::$replaying !== null ? self::$replaying['bulk'] : self::$bulk;
            if ($bulk !== null) {
                $data += array_filter(['bulk' => $bulk['how'], 'bulk_by' => $bulk['by']]);
            }

            $row = [
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
            ];
            DB::transaction(fn () => DB::table('timeline_events')->insert($row));
            app(TimelineWebhooks::class)->recorded(['occurred_at' => $row['occurred_at'], 'data' => $data] + $row);
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
