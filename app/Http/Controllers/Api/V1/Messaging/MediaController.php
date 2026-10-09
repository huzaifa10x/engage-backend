<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MediaStorage;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController extends Controller
{
    private const SHOWN_IN_BROWSER = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/3gpp', 'application/pdf'];

    /** Upload an attachment before sending it. Validated against Cloud API type/size limits. */
    public function store(Request $request, MediaStorage $storage, TenantContext $context, EntitlementService $entitlements): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.(100 * 1024)]]);
        // Plan storage allowance (uploads only: media your customers send is always received).
        $entitlements->ensureWithinLimit($context->tenant(), FeatureKey::MediaStorageMb, (int) ceil(($request->file('file')?->getSize() ?? 0) / 1048576));
        $file = $request->file('file');
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The file could not be uploaded. It may be larger than the server allows.']);
        }
        $mime = Media::canonicalMime((string) $file->getMimeType(), $file->getClientMimeType(), $file->getClientOriginalExtension());
        $type = Media::whatsappTypeFor($mime);

        if ($type === null) {
            throw ValidationException::withMessages(['file' => match (true) {
                str_contains($mime, 'webm') => 'WhatsApp does not accept WebM recordings. Send audio as OGG (Opus), MP3, M4A, AAC or AMR, and video as MP4.',
                str_starts_with($mime, 'audio/') => "WhatsApp does not accept {$mime} audio. Use OGG (Opus), MP3, M4A, AAC or AMR.",
                str_starts_with($mime, 'video/') => "WhatsApp does not accept {$mime} video. Use MP4 (H.264 video with AAC audio) or 3GP.",
                str_starts_with($mime, 'image/') => "WhatsApp does not accept {$mime} images. Use JPEG or PNG (WebP for stickers).",
                default => "WhatsApp does not accept {$mime} files. Send a PDF, Word, Excel, PowerPoint or text document instead.",
            }]);
        }
        $max = (int) config("engage.messaging.media_limits.{$type}.max");
        if ($file->getSize() > $max) {
            throw ValidationException::withMessages(['file' => sprintf('%s files can be at most %s MB on WhatsApp.', ucfirst($type), round($max / 1048576, 1))]);
        }

        $media = Media::query()->create([
            'direction' => 'outbound',
            'mime_type' => $mime,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 240),
            'file_size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'status' => 'pending',
            'created_by_membership_id' => $context->membership()?->id,
        ]);
        $media->forceFill(['disk' => $storage->disk(), 'path' => $storage->put($media, (string) file_get_contents($file->getRealPath())), 'status' => 'ready'])->save();

        return response()->json(['data' => [
            'id' => $media->id,
            'type' => $type,
            'mime_type' => $media->mime_type,
            'filename' => $media->filename,
            'file_size' => $media->file_size,
        ]], 201);
    }

    /** Stream a stored file. Access follows the conversation's number for message media. */
    public function show(Media $media, TenantContext $context, NumberAccess $access): StreamedResponse
    {
        abort_unless($media->isReady(), 404);

        $message = Message::query()->where('media_id', $media->id)->first();
        if ($message !== null) {
            $access->ensureCanAccess($context->membership()?->loadMissing('role') ?? abort(403), PhoneNumber::query()->findOrFail($message->phone_number_id));
        }

        // Customers can send any file. Only types a browser shows without running anything are opened
        // in the page; everything else (HTML, SVG, office files …) is downloaded, so a file sent by a
        // stranger can never run as a page of this site.
        $mime = (string) $media->mime_type;
        $inline = in_array($mime, self::SHOWN_IN_BROWSER, true) || str_starts_with($mime, 'audio/');

        return Storage::disk((string) $media->disk)->response((string) $media->path, $media->filename ?? basename((string) $media->path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }
}
