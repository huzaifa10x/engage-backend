<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * A workspace's compliance configuration (stored in tenants.settings.compliance), merged over
 * safe defaults. STOP / UNSUBSCRIBE and START / SUBSCRIBE can never be removed: a customer must
 * always be able to opt out with the standard words, and the in-chat Subscribe button relies
 * on SUBSCRIBE.
 */
final class ComplianceSettings
{
    public const LOCKED_OPT_OUT = ['stop', 'unsubscribe'];

    public const LOCKED_OPT_IN = ['start', 'subscribe'];

    public const MIN_MESSAGE_DAYS = 30;

    public const MIN_MEDIA_DAYS = 14;

    /**
     * @param  list<string>  $optOutKeywords
     * @param  list<string>  $optInKeywords
     */
    public function __construct(
        public readonly array $optOutKeywords,
        public readonly array $optInKeywords,
        public readonly bool $confirmOptOut,
        public readonly string $optOutReply,
        public readonly bool $confirmOptIn,
        public readonly string $optInReply,
        public readonly string $consentRequestText,
        public readonly bool $retentionEnabled,
        public readonly int $messageRetentionDays,
        public readonly int $mediaRetentionDays,
        public readonly int $marketingFrequencyCap = 0,
        public readonly bool $quietHoursEnabled = true,
        public readonly string $quietHoursStart = '22:00',
        public readonly string $quietHoursEnd = '08:00',
    ) {}

    public static function for(?Tenant $tenant): self
    {
        $stored = (array) (($tenant->settings ?? [])['compliance'] ?? []);
        $name = $tenant->name ?? 'us';

        return new self(
            self::keywords($stored['opt_out_keywords'] ?? config('engage.messaging.stop_keywords'), self::LOCKED_OPT_OUT),
            self::keywords($stored['opt_in_keywords'] ?? config('engage.messaging.start_keywords'), self::LOCKED_OPT_IN),
            (bool) ($stored['confirm_opt_out'] ?? true),
            (string) ($stored['opt_out_reply'] ?? 'You have been unsubscribed and will not receive further messages from us. Reply START to subscribe again.'),
            (bool) ($stored['confirm_opt_in'] ?? true),
            (string) ($stored['opt_in_reply'] ?? 'You are subscribed. Reply STOP at any time to unsubscribe.'),
            (string) ($stored['consent_request_text'] ?? "Would you like to receive offers and updates from {$name} on WhatsApp? You can unsubscribe at any time by replying STOP."),
            (bool) ($stored['retention_enabled'] ?? false),
            max(self::MIN_MESSAGE_DAYS, (int) ($stored['message_retention_days'] ?? 365)),
            max(self::MIN_MEDIA_DAYS, (int) ($stored['media_retention_days'] ?? 180)),
            max(0, (int) ($stored['marketing_frequency_cap'] ?? 0)),
            (bool) ($stored['quiet_hours_enabled'] ?? true),
            self::clock($stored['quiet_hours_start'] ?? null, '22:00'),
            self::clock($stored['quiet_hours_end'] ?? null, '08:00'),
        );
    }

    /**
     * Lower-cased, de-duplicated, with the locked keywords always first.
     *
     * @param  list<string>  $locked
     * @return list<string>
     */
    public static function keywords(mixed $words, array $locked): array
    {
        $clean = [];
        foreach (array_merge($locked, (array) $words) as $word) {
            $word = mb_strtolower(trim((string) preg_replace('/[\p{P}\p{S}\s]+/u', ' ', (string) $word)));
            if ($word !== '' && mb_strlen($word) <= 24) {
                $clean[$word] = true;
            }
        }

        return array_map('strval', array_keys($clean));
    }

    private static function clock(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }

    /**
     * Marketing campaigns do not send during quiet hours (workspace time zone). Returns when
     * sending may continue, or null when it may send now.
     */
    public function quietUntil(\DateTimeInterface $now, string $timezone): ?Carbon
    {
        if (! $this->quietHoursEnabled || $this->quietHoursStart === $this->quietHoursEnd) {
            return null;
        }
        $local = Carbon::instance($now)->setTimezone($timezone);
        $start = $local->copy()->setTimeFromTimeString($this->quietHoursStart);
        $end = $local->copy()->setTimeFromTimeString($this->quietHoursEnd);

        if ($start->lt($end)) { // same-day window, e.g. 01:00–06:00
            return $local->gte($start) && $local->lt($end) ? $end->utc() : null;
        }
        if ($local->gte($start)) { // overnight window, before midnight
            return $end->addDay()->utc();
        }

        return $local->lt($end) ? $end->utc() : null; // overnight window, after midnight
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'opt_out_keywords' => $this->optOutKeywords,
            'opt_in_keywords' => $this->optInKeywords,
            'locked_opt_out_keywords' => self::LOCKED_OPT_OUT,
            'locked_opt_in_keywords' => self::LOCKED_OPT_IN,
            'confirm_opt_out' => $this->confirmOptOut,
            'opt_out_reply' => $this->optOutReply,
            'confirm_opt_in' => $this->confirmOptIn,
            'opt_in_reply' => $this->optInReply,
            'consent_request_text' => $this->consentRequestText,
            'retention_enabled' => $this->retentionEnabled,
            'message_retention_days' => $this->messageRetentionDays,
            'media_retention_days' => $this->mediaRetentionDays,
            'min_message_retention_days' => self::MIN_MESSAGE_DAYS,
            'min_media_retention_days' => self::MIN_MEDIA_DAYS,
            'marketing_frequency_cap' => $this->marketingFrequencyCap,
            'quiet_hours_enabled' => $this->quietHoursEnabled,
            'quiet_hours_start' => $this->quietHoursStart,
            'quiet_hours_end' => $this->quietHoursEnd,
        ];
    }
}
