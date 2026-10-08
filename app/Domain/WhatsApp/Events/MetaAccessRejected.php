<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Events;

/**
 * Meta answered one of our API calls with an authorisation error. $objectId is the Graph object
 * the call was about (a WhatsApp Business Account id or a phone number id). Raised by GraphClient
 * for every call, so no caller has to remember to check.
 */
final readonly class MetaAccessRejected
{
    public function __construct(public string $objectId, public ?int $metaCode, public ?int $metaSubcode) {}
}
