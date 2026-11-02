<?php

namespace App\Services\TikTok;

use App\Exceptions\TikTokVideoException;
use Illuminate\Support\Facades\Process;

class TikTokVideoProbe
{
    /**
     * Read the container with ffprobe. The caller decides which values are acceptable.
     *
     * @return array{format: string, video_codec: string, audio_codec: string, duration: float, width: int, height: int}
     */
    public function inspect(string $absolutePath): array
    {
        $binary = (string) config('services.tiktok.ffprobe', 'ffprobe');
        if (! $this->binaryExists($binary)) {
            throw new TikTokVideoException("We couldn't check this video. Try again in a moment.");
        }

        $result = Process::timeout(20)->run([
            $binary,
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $absolutePath,
        ]);

        if (! $result->successful()) {
            throw new TikTokVideoException('This video needs to be an MP4 or MOV with H.264 video and AAC audio.');
        }

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded)) {
            throw new TikTokVideoException('This video needs to be an MP4 or MOV with H.264 video and AAC audio.');
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

    private function binaryExists(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary);
        }

        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
