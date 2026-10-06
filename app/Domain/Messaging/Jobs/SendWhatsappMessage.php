<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MediaStorage;
use App\Domain\Messaging\Services\PayloadBuilder;
use App\Domain\Messaging\Services\SendThroughput;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one queued message. This job is the ONLY code that sends a message to Meta, and it
 * paces every send through SendThroughput: per connected number, at the lowest of the plan's
 * messages-per-second, Meta's cap for the number, and 20 for coexistence numbers. Transient Meta errors are retried with backoff; permanent
 * errors mark the message failed with Meta's code for the UI.
 */
final class SendWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Throttling (130429 / 131056) and outages are retried; everything else fails fast. */
    private const RETRYABLE = [1, 2, 4, 17, 32, 613, 80007, 130429, 131000, 131016, 131048, 131056, 133004];

    /** Real exceptions (bugs, outages) allowed before the job is failed. */
    public int $maxExceptions = 5;

    /** Transient Meta errors retried by us (distinct from rate-limiter releases). */
    private const MAX_TRANSIENT_ATTEMPTS = 25;

    /** $maxMps is kept only so jobs queued before this change still load; it is ignored. */
    public function __construct(public readonly string $messageId, public readonly string $phoneNumberId, public readonly int $maxMps = 0)
    {
        $this->onQueue(QueueName::Messaging->value);
    }

    /**
     * Time-bounded rather than attempt-bounded: every rate-limiter release counts as an attempt,
     * so a big campaign on one number would otherwise exhaust `tries` without ever failing.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [2, 10, 30, 120, 300];
    }

    public function handle(GraphClient $graph, WhatsappCredentials $credentials, PayloadBuilder $builder, MediaStorage $storage, SendThroughput $throughput): void
    {
        $message = Message::query()->with(['contact', 'phoneNumber.wabaAccount', 'media'])->find($this->messageId);
        if ($message === null || $message->status !== MessageStatus::Queued) {
            return; // already sent (retry after a crash) or cancelled
        }

        $number = $message->phoneNumber;
        if ($number === null || $number->status !== PhoneNumberStatus::Connected || $number->wabaAccount === null) {
            $this->fail($message, 'number_unavailable', 'The WhatsApp number is no longer connected.');

            return;
        }

        try {
            $token = $credentials->tokenFor($number->wabaAccount);
            $metaMediaId = null;

            if ($message->media !== null) {
                $metaMediaId = $message->media->reusableMetaIdFor($number->id);
                if ($metaMediaId === null) {
                    if (! $message->media->isReady()) {
                        throw MessagingException::mediaNotReady();
                    }
                    $metaMediaId = $graph->uploadMedia(
                        $number->phone_number_id,
                        $storage->get($message->media),
                        (string) ($message->media->filename ?? 'file'),
                        (string) $message->media->mime_type,
                        $token,
                    );
                    $message->media->forceFill([
                        'meta_media_id' => $metaMediaId,
                        'uploaded_to_phone_number_id' => $number->id,
                        'meta_media_expires_at' => now()->addDays((int) config('engage.messaging.meta_media_ttl_days', 29)),
                    ])->save();
                }
            }

            // Throughput gate, immediately before the only call that sends a message to Meta.
            // The limit comes from the database right now (plan, Meta's cap, coexistence), never
            // from the request or the queued payload.
            $wait = $throughput->reserve($number);
            if ($wait === null) {
                if ($this->job !== null && config('queue.default') !== 'sync') {
                    $this->release($throughput->retryAfterSeconds()); // this number's queue is full for now

                    return;
                }
                // No queue to hand the job back to: wait for a slot instead of skipping the limit.
                do {
                    usleep(250_000);
                    $wait = $throughput->reserve($number);
                } while ($wait === null);
            }
            if ($wait > 0) {
                usleep($wait * 1000);
            }

            $result = $graph->sendMessage($number->phone_number_id, $builder->build($message, $metaMediaId), $token);
        } catch (MetaApiException $e) {
            if (in_array($e->metaCode, self::RETRYABLE, true) || $e->httpStatus >= 500) {
                if ($this->attempts() < self::MAX_TRANSIENT_ATTEMPTS) {
                    $this->release($this->backoff()[min($this->attempts() - 1, 4)]);

                    return;
                }
            }
            $this->fail($message, (string) ($e->metaCode ?? $e->httpStatus), $e->getMessage(), $e->error);

            return;
        } catch (MessagingException|WhatsappException $e) {
            $this->fail($message, $e->errorCode()->value, $e->getMessage());

            return;
        }

        $message->forceFill(['wamid' => $result['wamid'], 'status' => MessageStatus::Accepted])->save();

        // Learn identifiers Meta returns (phone for a BSUID send, or vice versa).
        $contact = $message->contact;
        if ($contact !== null) {
            $contact->forceFill(array_filter([
                'wa_id' => $contact->wa_id ?? $result['wa_id'],
                'bsuid' => $contact->bsuid ?? $result['user_id'],
            ]))->save();
        }

        MessageStored::dispatch($message, false);
    }

    public function failed(?Throwable $e): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message !== null && $message->status === MessageStatus::Queued) {
            $this->fail($message, 'send_failed', $e?->getMessage() ?? 'Sending failed.');
        }
    }

    /** @param array<string, mixed> $details */
    private function fail(Message $message, string $code, string $title, array $details = []): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'error_code' => mb_substr($code, 0, 32),
            'error_title' => mb_substr($title, 0, 255),
            'error_details' => $details ?: null,
        ])->save();

        MessageStored::dispatch($message, false);
    }
}
