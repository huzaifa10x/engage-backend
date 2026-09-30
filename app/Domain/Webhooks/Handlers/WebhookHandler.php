<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;

/** Must be idempotent: the same change can be processed more than once (retries, replays). */
interface WebhookHandler
{
    /** @return list<string> Meta webhook `field` values handled */
    public function fields(): array;

    public function handle(WebhookChange $change): ProcessResult;
}
