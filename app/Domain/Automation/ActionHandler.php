<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * One kind of scheduled action. The scheduler makes the shared checks (consent, a person owning the
 * chat, the customer replying, quiet hours, the frequency cap) before calling run().
 */
abstract class ActionHandler
{
    /**
     * Do it. Returns "done[:result]", "skipped:<why>" or "cancelled:<why>".
     *
     * @param  array<string, mixed>  $payload
     */
    abstract public function run(?Conversation $conversation, ?Contact $contact, array $payload): string;

    /** Messages to customers need consent; internal actions (snooze returns, team alerts) don't. */
    public function needsConsent(): bool
    {
        return false;
    }

    public function staysOutOfPersonsWay(): bool
    {
        return false;
    }

    public function cancelsOnReply(): bool
    {
        return false;
    }

    public function respectsQuietHours(): bool
    {
        return false;
    }

    /** Held for quiet hours: may it still go out at $next (e.g. before the 24 h window closes)? */
    public function mayWaitUntil(Conversation $conversation, Carbon $next): bool
    {
        return true;
    }
}
