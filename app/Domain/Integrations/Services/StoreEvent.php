<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Services;

/** What a store told us, in one shape whatever the store is. */
final class StoreEvent
{
    /**
     * @param  ?string  $event  one of Integration::EVENTS, or null when there is nothing to send (only $cancels applies)
     * @param  array<string, string>  $data  values for template variables (Integration::FIELDS)
     * @param  list<string>  $cancels  checkout ids whose waiting abandoned-checkout reminder is no longer needed
     */
    public function __construct(
        public readonly ?string $event,
        public readonly string $externalId = '',
        public readonly ?string $phone = null,
        public readonly ?string $country = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly bool $acceptsMarketing = false,
        public readonly array $data = [],
        public readonly array $cancels = [],
    ) {}
}
