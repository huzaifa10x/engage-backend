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
