<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MediaStorage;
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
    /** Upload an attachment before sending it. Validated against Cloud API type/size limits. */
    public function store(Request $request, MediaStorage $storage, TenantContext $context): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.(100 * 1024)]]);
        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $type = Media::whatsappTypeFor($mime);

        if ($type === null) {
            throw ValidationException::withMessages(['file' => "WhatsApp does not support {$mime} files."]);
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

        return Storage::disk((string) $media->disk)->response((string) $media->path, $media->filename ?? basename((string) $media->path), [
            'Content-Type' => (string) $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
