<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Messaging\Models\Media;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaMimeTest extends TestCase
{
    #[DataProvider('files')]
    public function test_sniffed_types_are_mapped_to_what_the_cloud_api_accepts(string $sniffed, string $client, string $extension, string $expected): void
    {
        $this->assertSame($expected, Media::canonicalMime($sniffed, $client, $extension));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function files(): array
    {
        return [
            'm4a voice note sniffed as mp4' => ['video/mp4', 'audio/mp4', 'm4a', 'audio/mp4'],
            'browser recording (audio/mp4)' => ['video/mp4', 'audio/mp4;codecs=mp4a.40.2', 'mp4', 'audio/mp4'],
            'real mp4 video stays video' => ['video/mp4', 'video/mp4', 'mp4', 'video/mp4'],
            'm4a alias' => ['audio/x-m4a', '', 'm4a', 'audio/mp4'],
            'aac adts' => ['audio/x-hx-aac-adts', 'audio/aac', 'aac', 'audio/aac'],
            'amr as unknown bytes' => ['application/octet-stream', 'audio/amr', 'amr', 'audio/amr'],
            'ogg opus' => ['audio/ogg', 'audio/ogg; codecs=opus', 'ogg', 'audio/ogg'],
            'docx is a zip' => ['application/zip', '', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'text file renamed to docx is not trusted' => ['text/plain', 'application/pdf', 'docx', 'text/plain'],
            'unknown binary is not upgraded' => ['application/octet-stream', 'image/jpeg', 'exe', 'application/octet-stream'],
            'webm stays unsupported' => ['video/webm', 'audio/webm', 'webm', 'video/webm'],
            'jpeg alias' => ['image/jpg', 'image/jpeg', 'jpg', 'image/jpeg'],
        ];
    }
}
