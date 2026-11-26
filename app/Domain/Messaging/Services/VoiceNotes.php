<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * WhatsApp shows a voice note (play button, transcript) only for OGG / Opus mono audio, and a play
 * button only up to 512 KB (about 2 minutes at 32 kbps). Phone browsers do not record that:
 * Chrome records WebM (Opus inside, so it is only repackaged), Safari MP4 / AAC (re-encoded).
 * Every recording sent as a voice note is converted here with ffmpeg before it is stored.
 */
final class VoiceNotes
{
    /** WhatsApp's own limit for a playable voice note. */
    public const PLAYABLE_BYTES = 512 * 1024;

    public function available(): bool
    {
        try {
            return Process::timeout(10)->run([$this->binary(), '-hide_banner', '-version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Converts a recording to OGG / Opus mono. Returns the path of the converted file (the caller
     * deletes it), or null when ffmpeg is missing or cannot read the recording.
     */
    public function toOggOpus(string $input): ?string
    {
        $output = tempnam(sys_get_temp_dir(), 'voice').'.ogg';
        try {
            $result = Process::timeout((int) config('engage.messaging.voice.timeout', 60))->run([
                $this->binary(), '-hide_banner', '-loglevel', 'error', '-y', '-i', $input,
                '-vn', '-map_metadata', '-1', '-c:a', 'libopus', '-b:a', (string) config('engage.messaging.voice.bitrate', '32k'),
                '-ac', '1', '-ar', '48000', '-application', 'voip', '-f', 'ogg', $output,
            ]);
        } catch (Throwable $e) {
            Log::warning('Voice note conversion could not run', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
            Log::warning('Voice note conversion failed', ['error' => mb_substr($result->errorOutput(), 0, 500)]);
            @unlink($output);

            return null;
        }

        return $output;
    }

    private function binary(): string
    {
        return (string) config('engage.messaging.voice.ffmpeg', 'ffmpeg');
    }
}
