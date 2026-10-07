<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MediaStorage;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Copies an inbound media file from Meta to our storage. Meta download URLs expire within
 * minutes and media is deleted from Meta after 30 days, so we fetch immediately and keep a copy.
 */
final class DownloadInboundMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $mediaId)
    {
        $this->onQueue(QueueName::Messaging->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(GraphClient $graph, WhatsappCredentials $credentials, MediaStorage $storage): void
    {
        $media = Media::query()->find($this->mediaId);
        if ($media === null || $media->status === 'ready' || $media->meta_media_id === null) {
            return;
        }

        $number = PhoneNumber::query()->with('wabaAccount')->findOrFail($media->uploaded_to_phone_number_id);
        $token = $credentials->tokenFor($number->wabaAccount);

        try {
            $info = $graph->getMedia($media->meta_media_id, $token, $number->phone_number_id);
            $bytes = $graph->downloadMedia($info['url'], $token);
        } catch (MetaApiException $e) {
            if ($e->isTransient() && $this->attempts() < $this->tries) {
                throw $e;
            }
            $media->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return;
        }

        $expected = $info['sha256'] ?? $media->sha256;
        if ($expected !== null && ! hash_equals(strtolower((string) $expected), hash('sha256', $bytes))) {
            $media->forceFill(['status' => 'failed', 'error' => 'SHA-256 mismatch between Meta metadata and downloaded file.'])->save();

            return;
        }

        $media->forceFill([
            'mime_type' => $info['mime_type'] ?? $media->mime_type,
            'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ]);
        $media->forceFill(['disk' => $storage->disk(), 'path' => $storage->put($media, $bytes), 'status' => 'ready', 'error' => null])->save();

        $message = Message::query()->where('media_id', $media->id)->first();
        if ($message !== null) {
            if ($message->origin !== MessageOrigin::History) { // imported history stays quiet
                MessageStored::dispatch($message, false);
            }
        }
    }
}
