<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;

/** Needs reply for N minutes in business hours: the assigned agent (or the leads) is told. */
final class ReplyAlert extends TeamAlert
{
    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        if (! $this->stillWaiting($conversation, $payload)) {
            return 'cancelled:answered';
        }
        $to = $this->owners($conversation);
        foreach ($to as $member) {
            $this->tools->notify($member, 'reply_overdue', "{$this->name($contact)} is waiting for a reply", "Waiting {$this->waited($conversation)}.", "/inbox?c={$conversation->id}");
        }

        return $to->isEmpty() ? 'skipped:nobody_to_tell' : 'done:notified_'.$to->count();
    }
}
