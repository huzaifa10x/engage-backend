<?php

declare(strict_types=1);

namespace App\Application\Compliance;

use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Applies a workspace's retention policy (must run inside its tenant context):
 *   • messages older than N days are redacted — text, payload and attachment link removed;
 *     the row, its status and its timestamps stay, so reports and billing still add up;
 *   • media files older than N days are deleted from storage.
 * Consent events and the audit log are never touched: they are the legal record.
 */
final class RetentionRunner
{
    /** @return array{messages: int, media: int} */
    public function apply(Tenant $tenant): array
    {
        $settings = ComplianceSettings::for($tenant);
        if (! $settings->retentionEnabled) {
            return ['messages' => 0, 'media' => 0];
        }

        $messages = 0;
        Message::query()->whereNull('redacted_at')->where('created_at', '<', now()->subDays($settings->messageRetentionDays))
            ->select(['id', 'template'])->chunkById(500, function ($chunk) use (&$messages): void {
                /** @var Message $message */
                foreach ($chunk as $message) {
                    Message::query()->whereKey($message->id)->update([
                        'body' => null,
                        'content' => null,
                        'media_id' => null,
                        // Keep which template it was (for reports), not what it said to whom.
                        'template' => $message->template !== null ? json_encode(array_intersect_key($message->template, array_flip(['id', 'name', 'language', 'category']))) : null,
                        'redacted_at' => now(),
                    ]);
                    $messages++;
                }
            });

        $media = 0;
        Media::query()->where('status', 'ready')->whereNotNull('path')->where('created_at', '<', now()->subDays($settings->mediaRetentionDays))
            ->chunkById(200, function ($chunk) use (&$media): void {
                /** @var Media $file */
                foreach ($chunk as $file) {
                    Storage::disk((string) $file->disk)->delete((string) $file->path);
                    $file->forceFill(['status' => 'expired', 'path' => null])->save();
                    $media++;
                }
            });

        return ['messages' => $messages, 'media' => $media];
    }
}
