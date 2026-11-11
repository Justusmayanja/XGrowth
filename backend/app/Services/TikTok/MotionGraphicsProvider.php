<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Support\TikTokCatalog;
use App\Support\TikTokVideoCatalog;
use RuntimeException;

/**
 * Builds ffmpeg argument arrays and renders a vertical motion-graphics clip.
 * Spoken text lives in the ASS file. It is not placed in an argument.
 */
class MotionGraphicsProvider
{
    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly AssBuilder $ass,
        private readonly TikTokVideoProbe $probe,
    ) {}

    public function key(): string
    {
        return 'motion';
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{video: string, preview: string, poster: string}
     */
    public function render(User $user, string $work, array $spec, SpeechProvider $speech): array
    {
        $scenes = is_array($spec['scenes'] ?? null) ? $spec['scenes'] : [];
        if ($scenes === []) {
            throw new RuntimeException("We couldn't make the video. Try again, or add your own.");
        }
        $voice = (string) ($spec['voice']['style'] ?? 'female');
        $fontDir = resource_path('video/fonts');
        $color = $this->color($spec);
        $logo = $this->logoPath($user, $spec);
        $files = [];
        foreach ($scenes as $scene) {
            $n = (int) ($scene['n'] ?? count($files) + 1);
            $narration = (string) ($scene['narration'] ?? '');
            $audio = $work.'/audio-'.hash('sha256', $narration.'|'.$voice.'|'.$speech->key()).'.m4a';
            if (! is_file($audio)) {
                $speech->speak($user, $narration, $voice, $audio);
            }
            $measured = $this->ffmpeg->duration($audio);
            if ($measured <= 0) {
                $measured = max(1.2, (float) ($scene['seconds_estimate'] ?? 3));
            }
            $seconds = $measured + TikTokVideoCatalog::AUDIO_PAD_SECONDS;
            $scene['seconds'] = $seconds;
            $ass = $work.'/scene-'.$n.'.ass';
            $this->ass->write($ass, $scene, is_array($spec['brand'] ?? null) ? $spec['brand'] : [], (bool) ($spec['captions'] ?? true));
            $out = $work.'/scene-'.$n.'.mp4';
            $iconName = (string) ($scene['icon'] ?? '');
            $icon = null;
            if ($iconName !== '') {
                $icon = $work.'/icon-'.$n.'.png';
                app(MotionIcon::class)->write($icon, $iconName);
            }
            $this->ffmpeg->run($this->sceneArguments($out, $audio, $ass, $seconds, $color, $logo, $fontDir, $icon, $iconName), 120);
            $files[] = $out;
        }

        $final = $work.'/source.mp4';
        $this->ffmpeg->run($this->assembleArguments($work, $files, $final, $spec), 180);
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
        $this->ffmpeg->run([
            '-ss', '0.4',
            '-i', $final,
            '-frames:v', '1',
            '-vf', 'scale=540:-1',
            $poster,
        ], 30);

        try {
            app(TikTokVideoService::class)->acceptProbe($this->probe->inspect($final));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TikTok render output failed its own checks', [
                'user_id' => $user->id,
            ]);
            throw new RuntimeException("We couldn't make the video. Try again, or add your own.");
        }

        return ['video' => $final, 'preview' => $preview, 'poster' => $poster];
    }

    /**
     * @return list<string>
     */
    public function sceneArguments(string $videoOut, string $audio, string $ass, float $seconds, string $color, ?string $logo, string $fontDir, ?string $icon = null, string $iconName = ''): array
    {
        $duration = number_format($seconds, 2, '.', '');
        $args = [
            '-f', 'lavfi',
            '-i', 'color=c='.$color.':s=1080x1920:r=30:d='.$duration,
            '-i', $audio,
        ];
        $filter = '[0:v]ass=filename='.$this->filterPath($ass).':fontsdir='.$this->filterPath($fontDir);
        if ($this->ffmpeg->hasFilter('fade')) {
            $outAt = max(0, $seconds - 0.15);
            $filter = '[0:v]fade=t=in:st=0:d=0.15,fade=t=out:st='.number_format($outAt, 2, '.', '').':d=0.15,ass=filename='.$this->filterPath($ass).':fontsdir='.$this->filterPath($fontDir);
        }
        $next = 2;
        if ($logo) {
            $args[] = '-i';
            $args[] = $logo;
            $filter .= '[base];['.$next.':v]scale=240:-1[logo];[base][logo]overlay=x=(W-w)/2:y=140';
            $next++;
            $filter .= $icon ? '[placed]' : '[v]';
        }
        if ($icon) {
            $args[] = '-i';
            $args[] = $icon;
            $from = $logo ? '[placed]' : $filter;
            if (! $logo) {
                $filter .= '[placed]';
                $from = '[placed]';
            }
            [$x, $y] = $this->iconMotion($iconName);
            $filter .= ';['.$next.':v]format=rgba,scale=200:200[icon];'.$from.'[icon]overlay=x=\''.$x.'\':y=\''.$y.'\':format=auto[v]';
        }
        if (! $logo && ! $icon) {
            $filter .= '[v]';
        }
        $args[] = '-filter_complex';
        $args[] = $filter;
        array_push($args, '-map', '[v]', '-map', '1:a', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-r', '30', '-c:a', 'aac', '-ar', '48000', '-ac', '2', '-shortest', $videoOut);

        return $args;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    public function assembleArguments(string $work, array $files, string $final, array $spec): array
    {
        $list = $work.'/concat.txt';
        $lines = array_map(fn ($file) => "file '".$this->concatPath($file)."'", $files);
        file_put_contents($list, implode("\n", $lines)."\n");
        $args = ['-f', 'concat', '-safe', '0', '-i', $list];
        $music = is_array($spec['music'] ?? null) ? ($spec['music'][0] ?? null) : null;
        if (is_string($music) && is_file($music)) {
            $args[] = '-i';
            $args[] = $music;
            $args[] = '-filter_complex';
            $args[] = '[1:a]volume=0.08,afade=t=out:st=0:d=1[bed];[0:a][bed]amix=inputs=2:duration=first:dropout_transition=0[a]';
            $args[] = '-map';
            $args[] = '0:v';
            $args[] = '-map';
            $args[] = '[a]';
        }
        array_push($args, '-c:v', 'libx264', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', '30', '-crf', '23', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $final);

        return $args;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function iconMotion(string $name): array
    {
        return match ($name) {
            'receipt' => ['(W-w)/2', '160+70*t'],
            'clock' => ['(W-w)/2', 'H*0.36+24*sin(2*PI*t/3)'],
            'steps' => ['(W-w)/2', 'H*0.40'],
            'arrow' => ['(W-w)/2', 'H*0.62-28*t'],
            default => ['40+90*t', 'H*0.34'],
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function color(array $spec): string
    {
        $brand = is_array($spec['brand'] ?? null) ? $spec['brand'] : [];
        $primary = $brand['primary'] ?? null;
        if (($brand['use_colors'] ?? false) && is_string($primary) && preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) {
            return '0x'.substr($primary, 1);
        }

        return '0x101113';
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function logoPath(User $user, array $spec): ?string
    {
        $brand = is_array($spec['brand'] ?? null) ? $spec['brand'] : [];
        if (! ($brand['use_logo'] ?? false)) {
            return null;
        }
        $key = $user->id.'/brand/logo.png';
        $disk = app(\App\Services\Storage\MediaStore::class)->disk();
        if (! $disk->exists($key)) {
            return null;
        }
        $local = app(\App\Services\Storage\MediaStore::class)->materialize(\App\Support\TikTokContentPosting::DISK, $key);

        return $local->path;
    }

    private function filterPath(string $path): string
    {
        return str_replace(['\\', ':', ','], ['/', '\\:', '\\,'], $path);
    }

    private function concatPath(string $path): string
    {
        return str_replace("'", "'\\''", $path);
    }
}
