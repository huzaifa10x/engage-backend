<?php

declare(strict_types=1);

namespace App\Domain\Growth;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * A workspace's Growth Hub switches (tenants.settings.growth), set by the platform team per
 * workspace while Growth Hub is in pilot. Nothing is on by default and no plan includes it:
 * entitlements are settings with no defaults (Technical Specification v1.2 §7).
 *
 *  enabled                       the feature flag: evidence is recorded and shown only when on
 *  started_at                    when tracking first started; contacts created before it are "Before tracking"
 *  strip_reference_before_flows  growth_strip_reference_before_flows (BE-05): chatbots read the message without its XG- code
 *  max_sites, max_accepted_events_per_calendar_month, detailed_history_days, aggregate_history_days
 *                                quotas, null = not set (pending the owner's commercial decision)
 */
final class GrowthSettings
{
    public const LIMITS = ['max_sites', 'max_accepted_events_per_calendar_month', 'detailed_history_days', 'aggregate_history_days'];

    /** @var array<string, self> */
    private static array $memo = [];

    /** @param array<string, ?int> $limits */
    public function __construct(
        public readonly bool $enabled,
        public readonly ?Carbon $startedAt,
        public readonly bool $stripReferenceBeforeFlows,
        public readonly array $limits,
    ) {}

    public static function for(Tenant $tenant): self
    {
        return self::$memo[$tenant->id.'|'.($tenant->updated_at?->getTimestamp() ?? 0)] ??= self::fromArray((array) (($tenant->settings ?? [])['growth'] ?? []));
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $limits = [];
        foreach (self::LIMITS as $key) {
            $limits[$key] = isset($raw[$key]) && is_numeric($raw[$key]) ? max(0, (int) $raw[$key]) : null;
        }

        return new self(
            (bool) ($raw['enabled'] ?? false),
            isset($raw['started_at']) && is_string($raw['started_at']) ? Carbon::parse($raw['started_at']) : null,
            (bool) ($raw['strip_reference_before_flows'] ?? false),
            $limits,
        );
    }

    /**
     * The new values for tenants.settings.growth. started_at is set the first time the flag goes on
     * and never moves afterwards, so "Before tracking" keeps meaning the same contacts.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function merge(Tenant $tenant, array $changes): array
    {
        $current = (array) (($tenant->settings ?? [])['growth'] ?? []);
        $next = array_merge($current, array_intersect_key($changes, array_flip(['enabled', 'strip_reference_before_flows', ...self::LIMITS])));
        if (! empty($next['enabled']) && empty($current['started_at'])) {
            $next['started_at'] = now()->toIso8601String();
        }

        return $next;
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'started_at' => $this->startedAt?->toIso8601String(),
            'strip_reference_before_flows' => $this->stripReferenceBeforeFlows,
        ] + $this->limits;
    }
}
