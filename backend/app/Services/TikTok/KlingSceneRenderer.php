<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Support\TikTokCatalog;
use App\Support\TikTokVideoCatalog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One short Kling scene, with the brand voice and captions laid on top.
 * The generated frame is not trusted to spell the words.
 */
class KlingSceneRenderer
{
    public function __construct(
        private readonly KlingVideoClient $kling,
        private readonly FfmpegRunner $ffmpeg,
        private readonly AssBuilder $ass,
        private readonly TikTokVideoProbe $probe,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     * @return array{video: string, preview: string, poster: string}
     */
    public function render(User $user, string $work, array $spec, SpeechProvider $speech): array
    {
        $scenes = is_array($spec['scenes'] ?? null) ? $spec['scenes'] : [];
        $narration = trim(implode(' ', array_map(
            fn ($scene) => is_array($scene) ? (string) ($scene['narration'] ?? '') : '',
            $scenes,
        )));
        if ($narration === '') {
            throw new RuntimeException(TikTokCatalog::RENDER_SCENE_FAILED);
        }

        $shots = [];
        foreach ($scenes as $scene) {
            if (is_array($scene) && ($scene['on_screen_text'] ?? '') !== '') {
                $shots[] = (string) $scene['on_screen_text'];
            }
        }
        $prompt = 'Realistic vertical scene, natural light, no readable text, no logos, no face filling the frame. '
            .implode('. ', array_slice($shots, 0, 3));
        $url = $this->kling->film(SceneText::plain($prompt, 800));
        $source = $work.'/kling.mp4';
        $download = Http::timeout(120)->sink($source)->get($url);
        if (! $download->successful() || ! is_file($source) || filesize($source) < 1000) {
            throw new RuntimeException(TikTokCatalog::RENDER_SCENE_FAILED);
        }

        $voice = (string) ($spec['voice']['style'] ?? 'female');
        $audio = $work.'/scene-voice.m4a';
        $speech->speak($user, $narration, $voice, $audio);
        $spoken = $this->ffmpeg->duration($audio);
        $filmed = $this->ffmpeg->duration($source);
        $seconds = $spoken > 0 ? $spoken : ($filmed > 0 ? $filmed : 10);

        $caption = [
            'n' => 1,
            'kind' => 'statement',
            'narration' => $narration,
            'on_screen_text' => (string) ($scenes[0]['on_screen_text'] ?? ''),
            'seconds' => $seconds,
        ];
        $ass = $work.'/scene.ass';
        $brand = is_array($spec['brand'] ?? null) ? $spec['brand'] : [];
        $this->ass->write($ass, $caption, $brand, (bool) ($spec['captions'] ?? true));

        $final = $work.'/source.mp4';
        $fontDir = resource_path('video/fonts');
        $this->ffmpeg->run([
            '-i', $source,
            '-i', $audio,
            '-filter_complex', '[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,ass=filename='.$this->filterPath($ass).':fontsdir='.$this->filterPath($fontDir).'[v]',
            '-map', '[v]',
            '-map', '1:a',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-r', '30',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            '-shortest',
            $final,
        ], 180);

        $preview = $work.'/preview.mp4';
        $this->ffmpeg->run([
            '-i', $final,
            '-vf', 'scale='.TikTokVideoCatalog::PREVIEW_WIDTH.':'.TikTokVideoCatalog::PREVIEW_HEIGHT,
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
        $this->ffmpeg->run(['-ss', '0.4', '-i', $final, '-frames:v', '1', '-vf', 'scale=540:-1', $poster], 30);

        try {
            app(TikTokVideoService::class)->acceptProbe($this->probe->inspect($final));
        } catch (\Throwable) {
            throw new RuntimeException(TikTokCatalog::RENDER_SCENE_FAILED);
        }

        return ['video' => $final, 'preview' => $preview, 'poster' => $poster];
    }

    private function filterPath(string $path): string
    {
        return str_replace(['\\', ':', ','], ['/', '\\:', '\\,'], $path);
    }
}
