<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Tenancy\Models\Tenant;

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
        ];
    }
}
