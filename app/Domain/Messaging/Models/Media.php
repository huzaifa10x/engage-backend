<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stored media file. Inbound: downloaded from Meta (URLs expire in minutes, so we copy).
 * Outbound: uploaded by the workspace, pushed to Meta per sending number on first use.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $direction
 * @property ?string $meta_media_id
 * @property ?string $mime_type
 * @property ?string $sha256
 * @property ?int $file_size
 * @property ?string $filename
 * @property ?string $disk
 * @property ?string $path
 * @property string $status
 * @property ?string $uploaded_to_phone_number_id
 * @property ?Carbon $meta_media_expires_at
 */
class Media extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'media';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'tenant_id', 'direction', 'meta_media_id', 'mime_type', 'sha256', 'file_size', 'filename', 'disk', 'path', 'status',
        'error', 'uploaded_to_phone_number_id', 'meta_media_expires_at', 'created_by_membership_id',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'meta_media_expires_at' => 'datetime'];
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->path !== null;
    }

    /** A Meta media ID reusable for this sending number (IDs are per number, valid ~30 days). */
    public function reusableMetaIdFor(string $phoneNumberId): ?string
    {
        return $this->meta_media_id !== null
            && $this->uploaded_to_phone_number_id === $phoneNumberId
            && $this->meta_media_expires_at?->isFuture() === true
            ? $this->meta_media_id
            : null;
    }

    /** What file sniffing and browsers report → the MIME type the Cloud API expects. */
    private const MIME_ALIASES = [
        'image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'image/x-png' => 'image/png',
        'audio/mp3' => 'audio/mpeg', 'audio/x-mp3' => 'audio/mpeg', 'audio/x-mpeg' => 'audio/mpeg', 'audio/mpeg3' => 'audio/mpeg',
        'audio/x-m4a' => 'audio/mp4', 'audio/m4a' => 'audio/mp4', 'audio/x-mp4' => 'audio/mp4',
        'audio/x-aac' => 'audio/aac', 'audio/aacp' => 'audio/aac', 'audio/x-hx-aac-adts' => 'audio/aac', 'audio/vnd.dlna.adts' => 'audio/aac',
        'audio/amr-nb' => 'audio/amr', 'audio/x-amr' => 'audio/amr',
        'audio/opus' => 'audio/ogg', 'audio/x-ogg' => 'audio/ogg', 'application/ogg' => 'audio/ogg', 'audio/vorbis' => 'audio/ogg',
        'video/3gp' => 'video/3gpp', 'video/x-m4v' => 'video/mp4',
        'application/x-pdf' => 'application/pdf',
    ];

    /** Containers that file sniffing cannot tell apart: trust the extension within these only. */
    private const EXTENSION_TYPES = [
        'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'amr' => 'audio/amr', 'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'txt' => 'text/plain',
    ];

    private const AMBIGUOUS = [
        'application/octet-stream', 'application/zip', 'application/x-zip-compressed', 'application/cdfv2', 'application/vnd.ms-office',
        'video/mp4', 'video/ogg', 'video/3gpp', 'text/plain', 'application/x-ole-storage',
    ];

    /**
     * The MIME type to store and send. The sniffed content type wins; the extension / browser
     * type only decides between formats that share a container (an .m4a voice note and an .mp4
     * video are both "video/mp4" to a sniffer, a .docx is a zip, an .amr is unknown bytes).
     */
    public static function canonicalMime(string $sniffed, ?string $clientMime = null, ?string $extension = null): string
    {
        $sniffed = strtolower(trim(explode(';', $sniffed)[0]));
        $client = strtolower(trim(explode(';', (string) $clientMime)[0]));
        $extension = strtolower((string) $extension);

        $mime = self::MIME_ALIASES[$sniffed] ?? $sniffed;

        if (in_array($sniffed, self::AMBIGUOUS, true)) {
            $byExtension = self::EXTENSION_TYPES[$extension] ?? null;
            $byClient = self::MIME_ALIASES[$client] ?? $client;
            $audioContainer = in_array($sniffed, ['video/mp4', 'video/ogg', 'video/3gpp'], true);

            if ($audioContainer) {
                // Audio-only recordings: accept an audio type from the extension or the browser.
                foreach ([$byExtension, $byClient] as $candidate) {
                    if (is_string($candidate) && str_starts_with($candidate, 'audio/')) {
                        return $candidate;
                    }
                }
            } elseif ($byExtension !== null && ! ($sniffed === 'text/plain' && $byExtension !== 'text/plain')) {
                return $byExtension;
            }
        }

        return $mime;
    }

    /** WhatsApp message type for this file's MIME type. */
    public static function whatsappTypeFor(string $mime): ?string
    {
        foreach ((array) config('engage.messaging.media_limits') as $type => $rule) {
            if (in_array($mime, (array) $rule['mimes'], true)) {
                return $type === 'sticker' ? 'sticker' : $type;
            }
        }

        return null;
    }
}
