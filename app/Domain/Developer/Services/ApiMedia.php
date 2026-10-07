<?php

declare(strict_types=1);

namespace App\Domain\Developer\Services;

use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Services\MediaStorage;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Files sent through the public API: uploaded directly, or fetched from a public URL.
 * Either way the file is checked against WhatsApp's accepted types and size limits and against
 * the plan's storage allowance, exactly like an attachment added in the inbox.
 */
final class ApiMedia
{
    /** Hard ceiling for a download, whatever the type turns out to be (WhatsApp's largest limit is 100 MB). */
    private const MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly UrlGuard $guard,
    ) {}

    public function fromUpload(UploadedFile $file, string $field = 'file'): Media
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([$field => 'The file could not be uploaded. It may be larger than the server allows.']);
        }
        $mime = Media::canonicalMime((string) $file->getMimeType(), $file->getClientMimeType(), $file->getClientOriginalExtension());

        return $this->store((string) file_get_contents($file->getRealPath()), $mime, $file->getClientOriginalName(), $field);
    }

    /** Downloads the file from a public https address (never an internal one) and stores it. */
    public function fromUrl(string $url, ?string $filename = null, string $field = 'media_url'): Media
    {
        $this->guard->assertPublic($url, $field);

        try {
            $response = Http::timeout(30)->connectTimeout(5)->withoutRedirecting()
                ->withOptions(['progress' => function ($total, $downloaded): void {
                    if ($downloaded > self::MAX_DOWNLOAD_BYTES || $total > self::MAX_DOWNLOAD_BYTES) {
                        throw new RuntimeException('too large');
                    }
                }])
                ->get($url);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([$field => str_contains($e->getMessage(), 'too large')
                ? 'The file at this address is larger than WhatsApp allows.'
                : 'The file could not be downloaded from this address.']);
        }
        if (! $response->successful() || $response->body() === '') {
            throw ValidationException::withMessages([$field => "The file could not be downloaded from this address (it answered {$response->status()})."]);
        }

        $body = $response->body();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = $filename ?: (basename($path) !== '' ? basename($path) : 'file');
        $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $declared = trim(explode(';', (string) $response->header('Content-Type'))[0]);

        return $this->store($body, Media::canonicalMime($sniffed, $declared, pathinfo($name, PATHINFO_EXTENSION)), $name, $field);
    }

    private function store(string $contents, string $mime, string $filename, string $field): Media
    {
        $size = strlen($contents);
        $type = Media::whatsappTypeFor($mime);
        if ($type === null) {
            throw ValidationException::withMessages([$field => "WhatsApp does not accept {$mime} files. Send JPEG or PNG images, MP4 video, OGG/MP3/M4A/AAC/AMR audio, or a PDF, Word, Excel, PowerPoint or text document."]);
        }
        $max = (int) config("engage.messaging.media_limits.{$type}.max");
        if ($size > $max) {
            throw ValidationException::withMessages([$field => sprintf('%s files can be at most %s MB on WhatsApp.', ucfirst($type), round($max / 1048576, 1))]);
        }
        // Plan storage allowance, as for uploads in the inbox.
        $this->entitlements->ensureWithinLimit($this->context->tenant(), FeatureKey::MediaStorageMb, (int) ceil($size / 1048576));

        $media = Media::query()->create([
            'direction' => 'outbound', 'mime_type' => $mime, 'filename' => mb_substr($filename, 0, 240),
            'file_size' => $size, 'sha256' => hash('sha256', $contents), 'status' => 'pending',
        ]);
        $media->forceFill(['disk' => $this->storage->disk(), 'path' => $this->storage->put($media, $contents), 'status' => 'ready'])->save();

        return $media;
    }

    /** @return array<string, mixed> */
    public static function describe(Media $media): array
    {
        return ['id' => $media->id, 'type' => Media::whatsappTypeFor((string) $media->mime_type), 'mime_type' => $media->mime_type, 'filename' => $media->filename, 'size' => $media->file_size];
    }
}
