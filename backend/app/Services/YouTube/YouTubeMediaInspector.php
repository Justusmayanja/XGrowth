<?php

namespace App\Services\YouTube;

use App\Services\TikTok\FfmpegRunner;
use RuntimeException;

class YouTubeMediaInspector
{
    public function __construct(private readonly FfmpegRunner $ffmpeg) {}

    /**
     * @return array{format: string, video_codec: string, audio_codec: string, duration: float, width: int, height: int}
     */
    public function inspect(string $absolutePath): array
    {
        try {
            $decoded = $this->ffmpeg->probe($absolutePath);
        } catch (RuntimeException) {
            throw new YouTubeRenderException('The local file could not be checked, so it was not saved. Nothing is uploaded.');
        }

        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $video = null;
        $audio = null;
        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }
            if ($video === null && ($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;
            }
            if ($audio === null && ($stream['codec_type'] ?? null) === 'audio') {
                $audio = $stream;
            }
        }

        $format = is_array($decoded['format'] ?? null) ? $decoded['format'] : [];

        return [
            'format' => (string) ($format['format_name'] ?? ''),
            'video_codec' => (string) ($video['codec_name'] ?? ''),
            'audio_codec' => (string) ($audio['codec_name'] ?? ''),
            'duration' => (float) ($format['duration'] ?? 0),
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
        ];
    }

    /**
     * @return array{format: string, video_codec: string, audio_codec: string, duration: float, width: int, height: int}
     */
    public function accept(string $absolutePath): array
    {
        if (! is_file($absolutePath) || filesize($absolutePath) < 1) {
            throw new YouTubeRenderException('The local file was empty, so it was not saved. Nothing is uploaded.');
        }
        if (filesize($absolutePath) > 150 * 1024 * 1024) {
            throw new YouTubeRenderException('The local file is over 150 MB, so it was not saved. Nothing is uploaded.');
        }

        $probe = $this->inspect($absolutePath);
        $format = strtolower($probe['format']);
        $container = str_contains($format, 'mp4')
            || str_contains($format, 'mov')
            || str_contains($format, 'webm')
            || str_contains($format, 'matroska');
        if (! $container || $probe['video_codec'] === '' || $probe['duration'] <= 0 || $probe['width'] < 2 || $probe['height'] < 2) {
            throw new YouTubeRenderException('The local file did not pass checks, so it was not saved. Nothing is uploaded.');
        }
        if ($probe['duration'] > 1800) {
            throw new YouTubeRenderException('The local file is longer than 30 minutes, so it was not saved. Nothing is uploaded.');
        }

        return $probe;
    }
}
