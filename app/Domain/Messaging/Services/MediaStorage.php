<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Where media bytes live. Private disk; files are only served through authorized API routes. */
final class MediaStorage
{
    public function disk(): string
    {
        return (string) config('engage.messaging.media_disk', 'local');
    }

    public function put(Media $media, string $contents, ?string $extension = null): string
    {
        $ext = $extension ?? $this->extensionFor((string) $media->mime_type);
        $path = sprintf('media/%s/%s/%s%s', $media->tenant_id, now()->format('Y/m'), $media->id, $ext !== '' ? '.'.$ext : '');
        Storage::disk($this->disk())->put($path, $contents);

        return $path;
    }

    public function get(Media $media): string
    {
        return (string) Storage::disk((string) $media->disk)->get((string) $media->path);
    }

    public function extensionFor(string $mime): string
    {
        return match (Str::before($mime, ';')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a',
            'audio/amr' => 'amr',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            default => '',
        };
    }
}
