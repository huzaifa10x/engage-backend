<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

/**
 * Who a webhook is about. Meta may send the phone (wa_id / from), the business-scoped user ID
 * (user_id / from_user_id), or both — never assume the phone is present.
 */
final readonly class ContactIdentity
{
    public function __construct(
        public ?string $waId,
        public ?string $bsuid,
        public ?string $parentBsuid = null,
        public ?string $username = null,
        public ?string $profileName = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->waId === null && $this->bsuid === null;
    }

    /**
     * Builds the identity for one message from a `messages` webhook value: the message's own
     * from / from_user_id, enriched with the matching `contacts[]` entry (profile, username).
     *
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $contacts
     */
    public static function fromInbound(array $message, array $contacts): self
    {
        $waId = self::str($message['from'] ?? null);
        $bsuid = self::str($message['from_user_id'] ?? null);

        $match = null;
        foreach ($contacts as $c) {
            if (($waId !== null && self::str($c['wa_id'] ?? null) === $waId) || ($bsuid !== null && self::str($c['user_id'] ?? null) === $bsuid)) {
                $match = $c;
                break;
            }
        }
        $match ??= count($contacts) === 1 ? $contacts[0] : [];

        return new self(
            $waId ?? self::str($match['wa_id'] ?? null),
            $bsuid ?? self::str($match['user_id'] ?? null),
            self::str($message['from_parent_user_id'] ?? $match['parent_user_id'] ?? null),
            self::str($match['profile']['username'] ?? null),
            self::str($match['profile']['name'] ?? null),
        );
    }

    /** @param array<string, mixed> $contact  one entry of a webhook `contacts[]` array */
    public static function fromContactBlock(array $contact): self
    {
        return new self(
            self::str($contact['wa_id'] ?? null),
            self::str($contact['user_id'] ?? null),
            self::str($contact['parent_user_id'] ?? null),
            self::str($contact['profile']['username'] ?? null),
            self::str($contact['profile']['name'] ?? null),
        );
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }
}
