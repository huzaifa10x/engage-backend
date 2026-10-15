<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Jobs;

use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Messaging\Models\Contact;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A contact got a tag or a changed field that a live flow starts on ("data change" trigger). */
final class StartChatbotsOnChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /**
     * @param  list<string>  $tagsAdded
     * @param  list<string>  $fieldsChanged
     */
    public function __construct(public readonly string $contactId, public readonly array $tagsAdded, public readonly array $fieldsChanged)
    {
        $this->onQueue(QueueName::Messaging->value);
    }

    public function handle(Chatbots $chatbots): void
    {
        $contact = Contact::query()->find($this->contactId);
        if ($contact !== null) {
            $chatbots->contactChanged($contact, $this->tagsAdded, $this->fieldsChanged);
        }
    }
}
