<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Messaging\Services\MessagingLimits;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\QualityEvent;

/**
 * Channels › a number's "Quality and limits" panel: Meta's quality rating, the daily messaging limit
 * and how much of it the business used in the moving 24 hours, how fast one campaign may use it,
 * Meta's latest warning, and the last 30 days of changes.
 *
 * Kept up to date by the phone_number_quality_update and account_alerts webhooks (recorded at once;
 * the rating is re-read 30 seconds later because the webhook does not carry it) and by the hourly
 * re-read of every number from the API (engage:whatsapp:refresh-limits).
 */
final class NumberHealth
{
    public const HISTORY_DAYS = 30;

    /** A warning older than this is history, not a current warning. */
    public const WARNING_DAYS = 7;

    public function __construct(private readonly MessagingLimits $limits, private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function for(PhoneNumber $number): array
    {
        $summary = $this->limits->summary($number);
        $settings = ComplianceSettings::for($this->context->tenant());
        $history = QualityEvent::query()
            ->where(fn ($q) => $q->where('phone_number_id', $number->id)
                ->orWhere(fn ($w) => $w->whereNull('phone_number_id')->where('waba_account_id', $number->waba_account_id)))
            ->where('occurred_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()->values(); // newest first (ids are time-ordered)

        $at = $history->search(fn (QualityEvent $e) => $e->occurred_at?->gte(now()->subDays(self::WARNING_DAYS))
            && ($e->event_type === 'quality.flagged' || str_starts_with((string) $e->event_type, 'alert.')));
        $warning = $at === false ? null : $history[$at];
        // A flag Meta lifted since (a newer UNFLAGGED) is no warning any more.
        if ($warning !== null && $warning->event_type === 'quality.flagged'
            && $history->take($at)->contains(fn (QualityEvent $e) => $e->event_type === 'quality.unflagged')) {
            $warning = null;
        }

        return [
            'quality_rating' => $number->quality_rating !== null ? strtoupper($number->quality_rating) : null,
            'messaging_limit_tier' => $number->messaging_limit_tier,
            'limit' => $summary['limit'],                 // people per moving 24 hours (null = unlimited)
            'used' => $summary['used'],                   // business-initiated, moving 24 hours, whole portfolio
            'remaining' => $summary['remaining'],
            'available_at' => $summary['available_at'],
            'campaign_hourly_share' => $settings->campaignHourlyShare,
            'campaign_hourly_cap' => $summary['limit'] !== null ? $settings->campaignHourlyCap($summary['limit']) : null,
            'warning' => $warning !== null ? ['at' => $warning->occurred_at?->toIso8601String()] + self::describe($warning) : null,
            'last_synced_at' => $number->getAttribute('last_synced_at')?->toIso8601String(),
            'history' => $history->map(fn (QualityEvent $e) => ['at' => $e->occurred_at?->toIso8601String(), 'type' => $e->event_type] + self::describe($e))->values()->all(),
        ];
    }

    /** @return array{title: string, detail: ?string, tone: string} */
    public static function describe(QualityEvent $e): array
    {
        $tier = fn (?string $t) => $t === null ? '?' : (($n = MessagingLimits::parseTier($t)) === MessagingLimits::UNLIMITED ? 'unlimited' : ($n !== null ? number_format($n).' people a day' : $t));
        $rating = fn (?string $r) => $r === null ? '?' : ucfirst(strtolower($r));
        /** @var array<string, mixed> $payload */
        $payload = $e->payload ?? [];
        /** @var array<string, mixed> $alert */
        $alert = is_array($payload['alert_info'] ?? null) ? $payload['alert_info'] : $payload;

        return match (true) {
            $e->event_type === 'rating.changed' => [
                'title' => 'Quality rating: '.$rating($e->old_value).' → '.$rating($e->new_value),
                'detail' => null,
                'tone' => match (strtoupper((string) $e->new_value)) {
                    'RED' => 'bad', 'YELLOW' => 'warn', default => 'good'
                },
            ],
            $e->event_type === 'limit.changed', $e->event_type === 'quality.upgrade', $e->event_type === 'quality.downgrade' => [
                'title' => 'Messaging limit: '.($e->old_value !== null ? $tier($e->old_value).' → ' : '').$tier($e->new_value),
                'detail' => $e->event_type === 'quality.downgrade' ? 'Lowered by Meta because of low quality.' : null,
                'tone' => $e->event_type === 'quality.downgrade' || (MessagingLimits::parseTier($e->new_value) ?? 0) < (MessagingLimits::parseTier($e->old_value) ?? 0) ? 'bad' : 'good',
            ],
            $e->event_type === 'quality.flagged' => [
                'title' => 'Meta flagged the number for low quality',
                'detail' => 'Customers are blocking or reporting its messages. Meta lowers the messaging limit if quality does not improve within 7 days.',
                'tone' => 'bad',
            ],
            $e->event_type === 'quality.unflagged' => ['title' => 'Meta removed the low-quality flag', 'detail' => null, 'tone' => 'good'],
            str_starts_with((string) $e->event_type, 'name.') => [
                'title' => 'Display name '.strtolower(str_replace('_', ' ', substr((string) $e->event_type, 5))),
                'detail' => $e->new_value,
                'tone' => $e->event_type === 'name.approved' ? 'good' : 'warn',
            ],
            str_starts_with((string) $e->event_type, 'alert.') => [
                'title' => 'Meta alert: '.ucfirst(strtolower(str_replace('_', ' ', (string) ($alert['alert_type'] ?? substr((string) $e->event_type, 6))))),
                'detail' => isset($alert['alert_description']) ? (string) $alert['alert_description'] : null,
                'tone' => strtoupper((string) ($alert['alert_severity'] ?? $e->new_value ?? '')) === 'CRITICAL' ? 'bad' : 'warn',
            ],
            default => ['title' => 'Quality update: '.str_replace(['.', '_'], ' ', (string) $e->event_type), 'detail' => null, 'tone' => 'info'],
        };
    }
}
