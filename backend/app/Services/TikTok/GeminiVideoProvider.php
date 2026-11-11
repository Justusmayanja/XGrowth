<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Support\GeminiCatalog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Gemini draws the picture and speaks. ffmpeg scales it to 9:16, burns the
 * caption file, and writes the preview and poster. Account text stays in the
 * caption file and is not an ffmpeg argument.
 */
class GeminiVideoProvider
{
    public function __construct(
        private readonly GeminiVideoClient $client,
        private readonly FfmpegRunner $ffmpeg,
        private readonly AssBuilder $ass,
        private readonly TikTokVideoProbe $probe,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     * @return array{video: string, preview: string, poster: string}
     */
    public function render(User $user, string $work, array $spec): array
    {
        $raw = $work.'/gemini.mp4';
        $this->client->download($user, $spec, $raw);

        $final = $work.'/final.mp4';
        $brand = is_array($spec['brand'] ?? null) ? $spec['brand'] : [];
        $captions = (bool) ($spec['captions'] ?? true);
        $vf = 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920';
        if ($captions) {
            $ass = $work.'/captions.ass';
            $seconds = $this->ffmpeg->ffprobeAvailable() ? $this->ffmpeg->duration($raw) : 15.0;
            $this->ass->write($ass, [
                'kind' => 'talk',
                'narration' => $this->spoken($spec),
                'seconds' => max(0.4, $seconds > 0 ? $seconds : 15.0),
            ], $brand, true);
            $vf .= ',ass=filename='.$this->filterPath($ass).':fontsdir='.$this->filterPath(resource_path('video/fonts'));
        }

        try {
            $this->ffmpeg->run([
                '-i', $raw,
                '-vf', $vf,
                '-c:v', 'libx264',
                '-profile:v', 'high',
                '-pix_fmt', 'yuv420p',
                '-r', '30',
                '-c:a', 'aac',
                '-ar', '48000',
                '-ac', '2',
                '-movflags', '+faststart',
                $final,
            ], 180);
            $preview = $work.'/preview.mp4';
            $this->ffmpeg->run([
                '-i', $final,
                '-vf', 'scale=540:-2',
                '-c:v', 'libx264',
                '-profile:v', 'high',
                '-pix_fmt', 'yuv420p',
                '-crf', '28',
                '-c:a', 'aac',
                '-b:a', '64k',
                '-movflags', '+faststart',
                $preview,
            ], 120);
            $poster = $work.'/poster.jpg';
            $this->ffmpeg->run([
                '-ss', '0.4',
                '-i', $final,
                '-frames:v', '1',
                '-vf', 'scale=540:-1',
                $poster,
            ], 30);
            app(TikTokVideoService::class)->acceptProbe($this->probe->inspect($final));
        } catch (Throwable $e) {
            Log::warning('TikTok render output failed its own checks', [
                'user_id' => $user->id,
                'error' => $e::class,
            ]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        return ['video' => $final, 'preview' => $preview, 'poster' => $poster];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function spoken(array $spec): string
    {
        $lines = [];
        $scenes = is_array($spec['scenes'] ?? null) ? $spec['scenes'] : [];
        foreach ($scenes as $scene) {
            if (is_array($scene) && trim((string) ($scene['narration'] ?? '')) !== '') {
                $lines[] = trim((string) $scene['narration']);
            }
        }

        return $lines !== [] ? implode(' ', $lines) : trim((string) ($spec['hook'] ?? ''));
    }

    private function filterPath(string $path): string
    {
        return str_replace(['\\', ':', ','], ['/', '\\:', '\\,'], $path);
    }
}
