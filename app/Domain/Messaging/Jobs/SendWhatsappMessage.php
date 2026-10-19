<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Application\WhatsApp\MarketingMessages;
use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MediaStorage;
use App\Domain\Messaging\Services\MessagingLimits;
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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one queued message. This job is the ONLY code that sends a message to Meta, and it
 * paces every send through SendThroughput: per connected number, at the lowest of the plan's
 * messages-per-second, Meta's cap for the number, and our ceiling (35, or 15 for coexistence
 * numbers). Transient Meta errors are retried with backoff; permanent errors mark the message
 * failed with Meta's code for the UI (and give its place in the daily messaging limit back).
 *
 * Route: campaign marketing templates of an account onboarded to the Marketing Messages API go to
 * POST /marketing_messages; everything else to POST /messages (MarketingMessages::routeFor). The
 * route used is stored on the message (send_route).
 */
final class SendWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Throttling (130429 / 131056) and outages are retried; everything else fails fast. */
    private const RETRYABLE = [1, 2, 4, 17, 32, 613, 80007, 130429, 131000, 131016, 131048, 131056, 133004];

    /** About the recipient, not the API: the Cloud API would refuse them too (undeliverable, marketing limits, opted out of marketing). */
    private const RECIPIENT_ERRORS = [131026, 131049, 131050];

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

            $payload = $builder->build($message, $metaMediaId);
            $route = MarketingMessages::routeFor($message, $number->wabaAccount);
            $result = $route === MarketingMessages::ROUTE_MM
                ? $this->sendMarketing($graph, $number->phone_number_id, $payload, $token, $route)
                : $graph->sendMessage($number->phone_number_id, $payload, $token);
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

        $message->forceFill(['wamid' => $result['wamid'], 'status' => MessageStatus::Accepted, 'send_route' => $route])->save();

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

    /**
     * Marketing Messages API, with the Cloud API as the safety net: a permanent MM API error sends the
     * same message through the Cloud API instead of failing it (errors about the recipient would
     * fail there too, so those are not repeated). Throttling and outages are retried as usual.
     *
     * @param  array<string, mixed>  $payload
     * @return array{wamid: string, wa_id: ?string, user_id: ?string, message_status: ?string}
     */
    private function sendMarketing(GraphClient $graph, string $phoneNumberId, array $payload, string $token, string &$route): array
    {
        try {
            return $graph->sendMarketingMessage($phoneNumberId, $payload, $token, (bool) config('engage.meta.marketing_messages.share_activity', true));
        } catch (MetaApiException $e) {
            if (in_array($e->metaCode, self::RETRYABLE, true) || $e->httpStatus >= 500 || in_array($e->metaCode, self::RECIPIENT_ERRORS, true)) {
                throw $e;
            }
            Log::warning('Marketing Messages API refused a message; sending it through the Cloud API.', ['message_id' => $this->messageId] + $e->context());
            $route = MarketingMessages::ROUTE_CLOUD;

            return $graph->sendMessage($phoneNumberId, $payload, $token);
        }
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

        // It never reached Meta: it does not count against the daily messaging limit.
        app(MessagingLimits::class)->release($message);

        MessageStored::dispatch($message, false);
    }
}
