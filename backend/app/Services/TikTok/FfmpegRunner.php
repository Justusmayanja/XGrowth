<?php

namespace App\Services\TikTok;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The only place this app starts ffmpeg or ffprobe. Arguments are an array.
 * Stderr is trimmed for logs and is never returned to the user.
 */
class FfmpegRunner
{
    public function run(array $arguments, int $timeout): void
    {
        $binary = $this->binary('ffmpeg');
        $result = Process::timeout($timeout)->run(array_merge([
            $binary,
            '-y',
            '-nostdin',
            '-threads', '2',
            '-hide_banner',
            '-loglevel', 'error',
        ], $arguments));

        if (! $result->successful()) {
            $stderr = trim($result->errorOutput());
            if (strlen($stderr) > 400) {
                $stderr = substr($stderr, 0, 400);
            }
            throw new RuntimeException($stderr !== '' ? 'ffmpeg failed' : 'ffmpeg failed');
        }
    }

    public function duration(string $path): float
    {
        $binary = $this->binary('ffprobe');
        $result = Process::timeout(20)->run([
            $binary,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nokey=1:noprint_wrappers=1',
            $path,
        ]);
        if (! $result->successful()) {
            return 0.0;
        }

        return (float) trim($result->output());
    }

    public function available(): bool
    {
        return $this->binaryExists('ffmpeg') && $this->binaryExists('ffprobe');
    }

    public function ffprobeAvailable(): bool
    {
        return $this->binaryExists('ffprobe');
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        if (! $this->binaryExists('ffmpeg')) {
            return [];
        }
        $result = Process::timeout(20)->run([$this->binary('ffmpeg'), '-hide_banner', '-filters']);

        return preg_split("/\r\n|\n|\r/", $result->output()) ?: [];
    }

    public function hasFilter(string $name): bool
    {
        foreach ($this->filters() as $line) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run ffmpeg and return both streams. Callers parse this locally and do not show it.
     *
     * @return array{ok: bool, stdout: string, stderr: string}
     */
    public function capture(array $arguments, int $timeout): array
    {
        $result = Process::timeout($timeout)->run(array_merge([
            $this->binary('ffmpeg'),
            '-y',
            '-nostdin',
            '-threads', '2',
            '-hide_banner',
        ], $arguments));

        return [
            'ok' => $result->successful(),
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function probe(string $path): array
    {
        $result = Process::timeout(20)->run([
            $this->binary('ffprobe'),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('ffprobe failed');
        }

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('ffprobe failed');
        }

        return $decoded;
    }

    private function binary(string $name): string
    {
        if ($name === 'ffprobe') {
            return (string) config('services.tiktok.ffprobe', 'ffprobe');
        }

        return (string) config('services.tiktok.ffmpeg', 'ffmpeg');
    }

    private function binaryExists(string $name): bool
    {
        $binary = $this->binary($name);
        if ($binary === '') {
            return false;
        }
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary);
        }
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
