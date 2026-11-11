<?php

namespace App\Services\YouTube;

use App\Models\User;
use App\Models\YouTubeVideo;
use App\Services\TikTok\ElevenLabsSpeechProvider;
use App\Services\TikTok\FfmpegRunner;
use App\Services\TikTok\OpenAiSpeechProvider;
use App\Support\SpeechDrivers;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Builds a local mp4 from a package or an edit plan. Spoken and on-screen words
 * are written into a caption file, never into an ffmpeg argument.
 */
class YouTubeFfmpegRenderer
{
    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly YouTubeMediaInspector $inspector,
    ) {}

    public function available(): bool
    {
        return $this->ffmpeg->available();
    }

    public function unavailableReason(): string
    {
        return 'ffmpeg and ffprobe are not available on this server. Install them, then make the local file again. Nothing is uploaded.';
    }

    /**
     * @return array{video: string, preview: string, poster: string, detail: string|null}
     */
    public function render(YouTubeVideo $video, string $work): array
    {
        if (! $this->available()) {
            throw new YouTubeRenderException($this->unavailableReason());
        }

        $final = $work.DIRECTORY_SEPARATOR.'render.mp4';
        $detail = null;
        if ($this->kind($video) === 'edit') {
            $detail = $this->renderEdit($video, $work, $final);
        } else {
            $this->renderCards($video, $work, $final);
        }

        $preview = $work.DIRECTORY_SEPARATOR.'preview.mp4';
        $poster = $work.DIRECTORY_SEPARATOR.'poster.jpg';
        try {
            $this->ffmpeg->run([
                '-i', $final,
                '-vf', 'scale=640:360:force_original_aspect_ratio=decrease,pad=640:360:(ow-iw)/2:(oh-ih)/2',
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-crf', '28',
                '-c:a', 'aac',
                '-b:a', '64k',
                '-movflags', '+faststart',
                $preview,
            ], 120);
            $this->ffmpeg->run([
                '-ss', '0.2',
                '-i', $final,
                '-frames:v', '1',
                '-vf', 'scale=640:-2',
                $poster,
            ], 30);
            $this->inspector->accept($final);
        } catch (YouTubeRenderException $e) {
            throw $e;
        } catch (Throwable) {
            throw new YouTubeRenderException('The local file could not be made. Nothing is uploaded.');
        }

        return [
            'video' => $final,
            'preview' => $preview,
            'poster' => $poster,
            'detail' => $detail,
        ];
    }

    public function kind(YouTubeVideo $video): string
    {
        $package = is_array($video->package) ? $video->package : [];
        $renderKind = (string) ($package['render']['kind'] ?? '');
        if ($video->mode === 'footage' || $renderKind === 'edit') {
            return 'edit';
        }
        if ($video->format === 'text_on_screen' || $renderKind === 'text') {
            return 'text';
        }
        if ($video->mode === 'series' || $video->format === 'faceless_voiceover' || $renderKind === 'narration') {
            return 'narration';
        }

        return 'clips';
    }

    /**
     * @return list<array{text: string, start: float, end: float}>
     */
    public function cues(YouTubeVideo $video): array
    {
        $package = is_array($video->package) ? $video->package : [];
        $runtime = (float) ($package['runtime_seconds'] ?? 0);
        $kind = $this->kind($video);
        if ($kind === 'clips') {
            $shots = is_array($package['shots'] ?? null) ? $package['shots'] : [];
            if ($shots !== []) {
                $cursor = 0.0;
                $cues = [];
                foreach ($shots as $shot) {
                    if (! is_array($shot)) {
                        continue;
                    }
                    $seconds = max(2.0, (float) ($shot['seconds'] ?? 4));
                    $cues[] = [
                        'text' => $this->cueText((string) ($shot['prompt'] ?? '')),
                        'start' => $cursor,
                        'end' => $cursor + $seconds,
                    ];
                    $cursor += $seconds;
                }

                return $this->filled($cues);
            }
        }
        if ($kind === 'narration') {
            $spoken = $this->spokenCues((string) $video->script, $runtime);
            if ($spoken !== []) {
                return $spoken;
            }
        }

        return $this->filled($this->timed(is_array($package['on_screen'] ?? null) ? $package['on_screen'] : [], $runtime));
    }

    /**
     * @param  list<array{start: float, end: float}>  $silences
     * @return list<array{0: float, 1: float}>
     */
    public function keepRanges(float $duration, array $silences): array
    {
        $cursor = 0.0;
        $keep = [];
        foreach ($silences as $span) {
            $start = max(0.0, (float) ($span['start'] ?? $span[0] ?? 0));
            $end = min($duration, (float) ($span['end'] ?? $span[1] ?? $start));
            if ($end <= $start) {
                continue;
            }
            if ($start > $cursor + 0.05) {
                $keep[] = [$cursor, $start];
            }
            $cursor = max($cursor, $end);
        }
        if ($duration > $cursor + 0.05) {
            $keep[] = [$cursor, $duration];
        }

        return $keep;
    }

    /**
     * @return list<string>
     */
    public function silenceArguments(string $path, float $over): array
    {
        $seconds = number_format(max(0.1, $over), 1, '.', '');

        return [
            '-loglevel', 'info',
            '-i', $path,
            '-af', 'silencedetect=n=-40dB:d='.$seconds,
            '-f', 'null',
            '-',
        ];
    }

    /**
     * @param  array<string, mixed>  $color
     */
    public function colorFilter(array $color): string
    {
        $contrast = number_format((float) ($color['contrast'] ?? 1), 2, '.', '');
        $saturation = number_format((float) ($color['saturation'] ?? 1), 2, '.', '');
        $brightness = number_format((float) ($color['brightness'] ?? 0), 2, '.', '');
        $warmth = (float) ($color['warmth'] ?? 0);

        return 'eq=contrast='.$contrast
            .':saturation='.$saturation
            .':brightness='.$brightness
            .',colorbalance=rs='.number_format($warmth, 2, '.', '')
            .':bs='.number_format(-1 * $warmth, 2, '.', '');
    }

    /**
     * @param  list<array<string, mixed>>  $zooms
     */
    public function zoomFilter(array $zooms, float $duration): string
    {
        if ($zooms === [] || $duration <= 0) {
            return 'scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2';
        }

        $expr = '1';
        foreach ($zooms as $zoom) {
            $scale = number_format((float) ($zoom['scale'] ?? 1), 2, '.', '');
            $at = max(0, (float) ($zoom['at_ratio'] ?? 0) * $duration);
            $hold = max(0.2, (float) ($zoom['hold_seconds'] ?? 1));
            $expr = 'if(between(in_time,'.number_format($at, 3, '.', '').','.number_format($at + $hold, 3, '.', '').'),'.$scale.','.$expr.')';
        }

        return "zoompan=z='{$expr}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:fps=30:s=1920x1080";
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array{0: float, 1: float}>  $keep
     */
    public function editFilter(array $plan, float $duration, array $keep, bool $hasAudio, ?string $srt): string
    {
        $ranges = $keep !== [] ? $keep : [[0.0, max(0.1, $duration)]];
        $outputDuration = 0.0;
        foreach ($ranges as [$start, $end]) {
            $outputDuration += max(0.0, $end - $start);
        }
        $color = $this->colorFilter(is_array($plan['color'] ?? null) ? $plan['color'] : []);
        $zoom = $this->zoomFilter(is_array($plan['zooms'] ?? null) ? $plan['zooms'] : [], max(0.1, $outputDuration));
        $loudness = (int) ($plan['audio']['loudness_lufs'] ?? -14);
        $look = $color.','.$zoom;
        if ($srt !== null) {
            $look .= ',subtitles='.$this->filterPath($srt);
        }
        $full = count($ranges) === 1
            && abs($ranges[0][0]) < 0.05
            && abs($ranges[0][1] - $duration) < 0.05;

        if ($full) {
            $filter = '[0:v]'.$look.',format=yuv420p[v]';
            if ($hasAudio) {
                $filter .= ';[0:a]loudnorm=I='.$loudness.':TP=-1.5:LRA=11[a]';
            }

            return $filter;
        }

        $parts = [];
        $concat = '';
        foreach ($ranges as $index => [$start, $end]) {
            $from = number_format($start, 3, '.', '');
            $to = number_format($end, 3, '.', '');
            $parts[] = "[0:v]trim=start={$from}:end={$to},setpts=PTS-STARTPTS[v{$index}]";
            if ($hasAudio) {
                $parts[] = "[0:a]atrim=start={$from}:end={$to},asetpts=PTS-STARTPTS[a{$index}]";
                $concat .= "[v{$index}][a{$index}]";
            } else {
                $concat .= "[v{$index}]";
            }
        }
        $count = count($ranges);
        if ($hasAudio) {
            $parts[] = "{$concat}concat=n={$count}:v=1:a=1[vc][ac]";
            $parts[] = '[vc]'.$look.',format=yuv420p[v]';
            $parts[] = '[ac]loudnorm=I='.$loudness.':TP=-1.5:LRA=11[a]';
        } else {
            $parts[] = "{$concat}concat=n={$count}:v=1:a=0[vc]";
            $parts[] = '[vc]'.$look.',format=yuv420p[v]';
        }

        return implode(';', $parts);
    }

    /**
     * @return list<string>
     */
    public function cardArguments(string $videoOut, string $audio, string $srt, float $seconds, bool $burnSubtitles): array
    {
        $duration = number_format(max(1, $seconds), 2, '.', '');
        $args = [
            '-f', 'lavfi',
            '-i', 'color=c=0x101113:s=1920x1080:r=30:d='.$duration,
            '-i', $audio,
        ];
        if ($burnSubtitles) {
            $args[] = '-vf';
            $args[] = 'subtitles='.$this->filterPath($srt);
        }
        array_push(
            $args,
            '-map', '0:v',
            '-map', '1:a',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-r', '30',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            '-shortest',
            '-movflags', '+faststart',
            $videoOut,
        );

        return $args;
    }

    private function renderCards(YouTubeVideo $video, string $work, string $final): void
    {
        $cues = $this->cues($video);
        if ($cues === []) {
            throw new YouTubeRenderException('This package has no on-screen lines, spoken text, or shots to render. Nothing is uploaded.');
        }
        $seconds = max(1.0, (float) $cues[array_key_last($cues)]['end']);
        $srt = $work.DIRECTORY_SEPARATOR.'captions.srt';
        $this->writeSrt($srt, $cues);
        $audio = $this->narrationAudio($video, $work, $seconds);
        $burn = $this->ffmpeg->hasFilter('subtitles') || $this->ffmpeg->hasFilter('ass');
        try {
            if ($burn) {
                $this->ffmpeg->run($this->cardArguments($final, $audio, $srt, $seconds, true), 180);
            } else {
                $this->ffmpeg->run($this->overlayArguments($work, $final, $audio, $cues, $seconds), 180);
            }
        } catch (RuntimeException) {
            throw new YouTubeRenderException('The local file could not be made. Nothing is uploaded.');
        }
    }

    private function renderEdit(YouTubeVideo $video, string $work, string $final): ?string
    {
        $source = $this->sourcePath($video);
        foreach (['eq', 'loudnorm', 'zoompan', 'silencedetect'] as $filter) {
            if (! $this->ffmpeg->hasFilter($filter)) {
                throw new YouTubeRenderException('ffmpeg is missing the '.$filter.' filter, so the edit was not made. Install a full ffmpeg build, then try again. Nothing is uploaded.');
            }
        }

        try {
            $probed = $this->inspector->inspect($source);
        } catch (YouTubeRenderException) {
            throw new YouTubeRenderException('The uploaded footage could not be read. Upload it again. Nothing is sent to YouTube.');
        }

        $plan = is_array($video->package['edit'] ?? null) ? $video->package['edit'] : [];
        $over = (float) ($plan['silence']['trim_over_seconds'] ?? 0.6);
        $detected = $this->ffmpeg->capture($this->silenceArguments($source, $over), 120);
        if (! $detected['ok']) {
            throw new YouTubeRenderException('Silence in the footage could not be measured, so the edit was not made. Nothing is uploaded.');
        }
        $duration = $probed['duration'] > 0 ? $probed['duration'] : $this->ffmpeg->duration($source);
        if ($duration <= 0) {
            throw new YouTubeRenderException('The uploaded footage has no duration, so the edit was not made. Nothing is uploaded.');
        }
        $silences = $this->silenceSpans($detected['stderr'], $duration, $over);
        $keep = $this->keepRanges($duration, $silences);
        $trimmed = $keep !== [] && ! $this->sameSpan($keep, $duration);
        $captions = is_array($plan['captions'] ?? null) ? $plan['captions'] : [];
        $srt = null;
        if ($captions !== []) {
            $srt = $work.DIRECTORY_SEPARATOR.'captions.srt';
            $this->writeSrt($srt, $this->timed($captions, $trimmed ? $this->spanLength($keep) : $duration));
        }
        $hasAudio = $probed['audio_codec'] !== '';
        $filter = $this->editFilter($plan, $duration, $trimmed ? $keep : [[0.0, $duration]], $hasAudio, $srt !== null && ($this->ffmpeg->hasFilter('subtitles') || $this->ffmpeg->hasFilter('ass')) ? $srt : null);
        $args = ['-i', $source];
        if (! $hasAudio) {
            array_push($args, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000');
        }
        array_push($args, '-filter_complex', $filter, '-map', '[v]');
        if ($hasAudio) {
            array_push($args, '-map', '[a]', '-c:a', 'aac', '-ar', '48000', '-ac', '2');
        } else {
            array_push($args, '-map', '1:a', '-c:a', 'aac', '-shortest');
        }
        array_push($args, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $final);
        try {
            $this->ffmpeg->run($args, 180);
        } catch (RuntimeException) {
            throw new YouTubeRenderException('The edited file could not be made. Nothing is uploaded.');
        }

        if ($srt !== null && ! ($this->ffmpeg->hasFilter('subtitles') || $this->ffmpeg->hasFilter('ass'))) {
            $this->overlayExisting($final, $work, $this->timed($captions, $this->ffmpeg->duration($final) ?: $duration));
        }

        if (! $trimmed) {
            return 'No silence longer than '.number_format($over, 1, '.', '').' seconds was removed.';
        }

        return null;
    }

    /**
     * @param  list<array{text: string, start: float, end: float}>  $cues
     * @return list<string>
     */
    private function overlayArguments(string $work, string $videoOut, string $audio, array $cues, float $seconds): array
    {
        $duration = number_format(max(1, $seconds), 2, '.', '');
        $args = [
            '-f', 'lavfi',
            '-i', 'color=c=0x101113:s=1920x1080:r=30:d='.$duration,
            '-i', $audio,
        ];
        $filter = '[0:v]format=yuv420p[base]';
        $current = 'base';
        foreach (array_values($cues) as $index => $cue) {
            $png = $work.DIRECTORY_SEPARATOR.'card-'.$index.'.png';
            $this->writeCard($png, $cue['text']);
            $args[] = '-i';
            $args[] = $png;
            $input = $index + 2;
            $next = 'ov'.$index;
            $start = number_format($cue['start'], 3, '.', '');
            $end = number_format($cue['end'], 3, '.', '');
            $filter .= ";[{$input}:v]scale=1920:1080[{$next}s];[{$current}][{$next}s]overlay=enable='between(t,{$start},{$end})'[{$next}]";
            $current = $next;
        }
        array_push(
            $args,
            '-filter_complex', $filter,
            '-map', '['.$current.']',
            '-map', '1:a',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-r', '30',
            '-c:a', 'aac',
            '-shortest',
            '-movflags', '+faststart',
            $videoOut,
        );

        return $args;
    }

    /**
     * @param  list<array{text: string, start: float, end: float}>  $cues
     */
    private function overlayExisting(string $video, string $work, array $cues): void
    {
        $cues = $this->filled($cues);
        if ($cues === []) {
            return;
        }
        $out = $work.DIRECTORY_SEPARATOR.'captioned.mp4';
        $args = ['-i', $video];
        $filter = '[0:v]format=yuv420p[base]';
        $current = 'base';
        foreach (array_values($cues) as $index => $cue) {
            $png = $work.DIRECTORY_SEPARATOR.'edit-card-'.$index.'.png';
            $this->writeCard($png, $cue['text']);
            $args[] = '-i';
            $args[] = $png;
            $input = $index + 1;
            $next = 'ov'.$index;
            $start = number_format($cue['start'], 3, '.', '');
            $end = number_format($cue['end'], 3, '.', '');
            $filter .= ";[{$input}:v]scale=1920:1080[{$next}s];[{$current}][{$next}s]overlay=enable='between(t,{$start},{$end})'[{$next}]";
            $current = $next;
        }
        array_push($args, '-filter_complex', $filter, '-map', '['.$current.']', '-map', '0:a?', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', $out);
        try {
            $this->ffmpeg->run($args, 180);
        } catch (RuntimeException) {
            throw new YouTubeRenderException('The edited file could not be captioned. Nothing is uploaded.');
        }
        if (! rename($out, $video)) {
            throw new YouTubeRenderException('The edited file could not be captioned. Nothing is uploaded.');
        }
    }

    private function narrationAudio(YouTubeVideo $video, string $work, float $seconds): string
    {
        $path = $work.DIRECTORY_SEPARATOR.'narration.m4a';
        $driver = (string) config('services.youtube.tts_driver', 'local');
        if ($this->kind($video) === 'narration' && in_array($driver, [SpeechDrivers::OPENAI, SpeechDrivers::ELEVENLABS], true)) {
            $spoken = trim(preg_replace('/\[ON SCREEN:[^\]]*\]/', ' ', (string) $video->script) ?? '');
            $user = $video->user ?? User::query()->find($video->user_id);
            if (! $user instanceof User || $spoken === '') {
                throw new YouTubeRenderException('The voice could not be made. Nothing is uploaded.');
            }
            $provider = $driver === SpeechDrivers::ELEVENLABS
                ? app(ElevenLabsSpeechProvider::class)
                : app(OpenAiSpeechProvider::class);
            try {
                $provider->speak($user, $spoken, 'female', $path);
            } catch (Throwable) {
                throw new YouTubeRenderException('The voice could not be made. Switch the speech driver to local, then try again. Nothing is uploaded.');
            }
            if (! is_file($path)) {
                throw new YouTubeRenderException('The voice could not be made. Nothing is uploaded.');
            }

            return $path;
        }

        $tone = $this->kind($video) === 'narration'
            ? 'sine=frequency=220:duration='.number_format(max(1, $seconds), 2, '.', '')
            : 'anullsrc=channel_layout=stereo:sample_rate=48000';
        try {
            $this->ffmpeg->run([
                '-f', 'lavfi',
                '-i', $tone,
                '-t', number_format(max(1, $seconds), 2, '.', ''),
                '-c:a', 'aac',
                '-ar', '48000',
                '-ac', '2',
                $path,
            ], 60);
        } catch (RuntimeException) {
            throw new YouTubeRenderException('The local audio could not be made. Nothing is uploaded.');
        }

        return $path;
    }

    private function sourcePath(YouTubeVideo $video): string
    {
        $relative = (string) $video->source_path;
        if ($relative === '' || ! Storage::disk('local')->exists($relative)) {
            throw new YouTubeRenderException('The uploaded footage is missing. Upload it again. Nothing is sent to YouTube.');
        }

        return Storage::disk('local')->path($relative);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{text: string, start: float, end: float}>
     */
    private function timed(array $rows, float $runtime): array
    {
        $cues = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $text = $this->cueText((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $cues[] = ['text' => $text, 'start' => $this->clock((string) ($row['at'] ?? '0:00')), 'end' => 0.0];
        }
        foreach ($cues as $index => $cue) {
            $next = $cues[$index + 1]['start'] ?? null;
            $end = is_float($next) || is_int($next) ? (float) $next : $cue['start'] + 4;
            if ($end <= $cue['start']) {
                $end = $cue['start'] + 4;
            }
            $cues[$index]['end'] = $end;
        }
        if ($cues === [] && $runtime > 0) {
            return [];
        }
        if (count($cues) === 1 && $runtime > $cues[0]['end']) {
            $cues[0]['end'] = $runtime;
        }

        return $cues;
    }

    /**
     * @return list<array{text: string, start: float, end: float}>
     */
    private function spokenCues(string $script, float $runtime): array
    {
        $plain = trim(preg_replace('/\[ON SCREEN:[^\]]*\]/', ' ', $script) ?? '');
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
        if ($plain === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!?])\s+/', $plain) ?: [];
        $parts = array_values(array_filter(array_map(fn ($part) => $this->cueText((string) $part), $parts)));
        if ($parts === []) {
            return [];
        }
        $weights = array_map(fn (string $part) => max(1, str_word_count($part)), $parts);
        $total = array_sum($weights);
        $seconds = max(8.0, $runtime, ($total / 150) * 60);
        $cursor = 0.0;
        $cues = [];
        foreach ($parts as $index => $part) {
            $span = $seconds * ($weights[$index] / $total);
            $cues[] = ['text' => $part, 'start' => $cursor, 'end' => $cursor + $span];
            $cursor += $span;
        }

        return $cues;
    }

    /**
     * @param  list<array{text: string, start: float, end: float}>  $cues
     * @return list<array{text: string, start: float, end: float}>
     */
    private function filled(array $cues): array
    {
        return array_values(array_filter($cues, fn (array $cue) => $cue['text'] !== '' && $cue['end'] > $cue['start']));
    }

    /**
     * @param  list<array{text: string, start: float, end: float}>  $cues
     */
    private function writeSrt(string $path, array $cues): void
    {
        $body = '';
        foreach (array_values($cues) as $index => $cue) {
            $text = str_replace('-->', 'to', $cue['text']);
            $body .= ($index + 1)."\n".$this->srtTime($cue['start']).' --> '.$this->srtTime($cue['end'])."\n".$text."\n\n";
        }
        if (file_put_contents($path, $body) === false) {
            throw new YouTubeRenderException('The caption file could not be written. Nothing is uploaded.');
        }
    }

    private function writeCard(string $path, string $text): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new YouTubeRenderException('ffmpeg has no subtitles filter and PHP GD is missing, so on-screen text cannot be burned in. Nothing is uploaded.');
        }
        $image = imagecreatetruecolor(1920, 1080);
        if ($image === false) {
            throw new YouTubeRenderException('On-screen text could not be drawn. Nothing is uploaded.');
        }
        $background = imagecolorallocate($image, 16, 17, 19);
        $ink = imagecolorallocate($image, 244, 244, 242);
        imagefilledrectangle($image, 0, 0, 1920, 1080, $background);
        $font = $this->fontFile();
        $lines = $this->wrap($text, $font ? 32 : 48);
        if ($font) {
            $size = 54;
            $lineHeight = 72;
            $top = (int) ((1080 - (count($lines) * $lineHeight)) / 2);
            foreach ($lines as $index => $line) {
                $box = imagettfbbox($size, 0, $font, $line);
                $width = is_array($box) ? abs($box[2] - $box[0]) : 800;
                imagettftext($image, $size, 0, (int) max(40, (1920 - $width) / 2), $top + ($index * $lineHeight), $ink, $font, $line);
            }
        } else {
            $top = (int) ((1080 - (count($lines) * 20)) / 2);
            foreach ($lines as $index => $line) {
                imagestring($image, 5, 80, $top + ($index * 20), $line, $ink);
            }
        }
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function fontFile(): ?string
    {
        $matches = glob(resource_path('video/fonts').DIRECTORY_SEPARATOR.'*.{ttf,otf}', GLOB_BRACE);
        if (is_array($matches)) {
            foreach ($matches as $match) {
                if (is_file($match)) {
                    return $match;
                }
            }
        }
        foreach ([
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $wrapped = wordwrap($text, $width, "\n", true);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $wrapped))));

        return array_slice($lines === [] ? [$text] : $lines, 0, 8);
    }

    private function cueText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $text)) ?? '');

        return Str::limit($text, 180, '');
    }

    private function clock(string $label): float
    {
        $parts = explode(':', $label);
        if (count($parts) === 3) {
            return ((int) $parts[0]) * 3600 + ((int) $parts[1]) * 60 + (float) $parts[2];
        }

        return ((int) ($parts[0] ?? 0)) * 60 + (float) ($parts[1] ?? 0);
    }

    private function srtTime(float $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $whole = (int) floor($seconds % 60);
        $millis = (int) round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $whole, $millis);
    }

    /**
     * @return list<array{start: float, end: float}>
     */
    private function silenceSpans(string $stderr, float $duration, float $over): array
    {
        $spans = [];
        $start = null;
        foreach (preg_split('/\r\n|\n|\r/', $stderr) ?: [] as $line) {
            if (preg_match('/silence_start:\s*(-?\d+(?:\.\d+)?)/', $line, $match) === 1) {
                $start = (float) $match[1];
            }
            if ($start !== null && preg_match('/silence_end:\s*(-?\d+(?:\.\d+)?)/', $line, $match) === 1) {
                $end = (float) $match[1];
                if ($end - $start >= $over) {
                    $spans[] = ['start' => max(0, $start), 'end' => min($duration, $end)];
                }
                $start = null;
            }
        }
        if ($start !== null && $duration - $start >= $over) {
            $spans[] = ['start' => max(0, $start), 'end' => $duration];
        }

        return $spans;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $keep
     */
    private function sameSpan(array $keep, float $duration): bool
    {
        return count($keep) === 1 && abs($keep[0][0]) < 0.05 && abs($keep[0][1] - $duration) < 0.05;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $keep
     */
    private function spanLength(array $keep): float
    {
        $length = 0.0;
        foreach ($keep as [$start, $end]) {
            $length += max(0, $end - $start);
        }

        return $length;
    }

    private function filterPath(string $path): string
    {
        return str_replace(['\\', ':', ','], ['/', '\\:', '\\,'], $path);
    }
}
