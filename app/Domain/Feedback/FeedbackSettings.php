<?php

declare(strict_types=1);

namespace App\Domain\Feedback;

use App\Domain\Tenancy\Models\Tenant;

/**
 * Inbox & Automations › Satisfaction and feedback (tenant settings "feedback").
 *
 *  csat_enabled      after a support chat is closed, a one-tap rating (Good / OK / Poor), inside the 24-hour window only
 *  csat_question     the rating question
 *  csat_thanks       the reply to an answer
 *  review_enabled    a feedback / review request with an approved utility template
 *  review_on_orders  after a delivered WooCommerce order
 *  review_on_bookings after a completed booking (reported through the API)
 *  review_template   {name, language, body: list<string>} — body values may use contact fields ({{first_name}}) and {{reference}}: the order number or the booking reference
 */
final readonly class FeedbackSettings
{
    public const DEFAULT_QUESTION = 'How was your chat with us today?';

    public const DEFAULT_THANKS = 'Thank you for your feedback!';

    /** @param ?array{name: string, language: string, body: list<string>} $reviewTemplate */
    public function __construct(
        public bool $csatEnabled,
        public string $csatQuestion,
        public string $csatThanks,
        public bool $reviewEnabled,
        public bool $reviewOnOrders,
        public bool $reviewOnBookings,
        public ?array $reviewTemplate,
    ) {}

    public static function for(?Tenant $tenant): self
    {
        $s = (array) (($tenant->settings ?? [])['feedback'] ?? []);
        $t = is_array($s['review_template'] ?? null) && is_string($s['review_template']['name'] ?? null) ? [
            'name' => (string) $s['review_template']['name'],
            'language' => (string) ($s['review_template']['language'] ?? 'en'),
            'body' => array_values(array_map('strval', (array) ($s['review_template']['body'] ?? []))),
        ] : null;

        return new self(
            (bool) ($s['csat_enabled'] ?? false),
            mb_substr(trim((string) ($s['csat_question'] ?? '')) ?: self::DEFAULT_QUESTION, 0, 1024),
            mb_substr(trim((string) ($s['csat_thanks'] ?? '')) ?: self::DEFAULT_THANKS, 0, 1024),
            (bool) ($s['review_enabled'] ?? false) && $t !== null,
            (bool) ($s['review_on_orders'] ?? true),
            (bool) ($s['review_on_bookings'] ?? true),
            $t,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'csat_enabled' => $this->csatEnabled, 'csat_question' => $this->csatQuestion, 'csat_thanks' => $this->csatThanks,
            'review_enabled' => $this->reviewEnabled, 'review_on_orders' => $this->reviewOnOrders, 'review_on_bookings' => $this->reviewOnBookings,
            'review_template' => $this->reviewTemplate,
        ];
    }
}
