<?php

namespace App\Services\YouTube;

use App\Models\YouTubeVideo;
use App\Services\Storage\MediaStore;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class YouTubeRenderService
{
    public const DISK = 'youtube_media';

    public function __construct(
        private readonly YouTubeFfmpegRenderer $renderer,
        private readonly YouTubeMediaInspector $inspector,
        private readonly YouTubeTranscriber $transcriber,
        private readonly YouTubeEngineService $engine,
        private readonly MediaStore $media,
    ) {}

    public function render(YouTubeVideo $video): YouTubeVideo
    {
        if (! in_array($video->status, ['waiting', 'queued', 'awaiting_edit'], true)) {
            throw ValidationException::withMessages([
                'render' => ['This video is not waiting for a local file.'],
            ]);
        }

        $status = $video->status;
        if (! $this->renderer->available()) {
            return $this->block($video, $status, $this->renderer->unavailableReason());
        }

        $this->sweepWork();
        $work = $this->workDir($video->id);
        $committed = false;
        try {
            $files = $this->renderer->render($video, $work);
            foreach (['video', 'preview', 'poster'] as $name) {
                if (! is_file($files[$name]) || filesize($files[$name]) < 1) {
                    throw new YouTubeRenderException('The local file was empty, so it was not saved. Nothing is uploaded.');
                }
            }
            $accepted = $this->inspector->accept($files['video']);
            $stored = $this->store($video, $files);
            $this->commit($video, $status, $stored, $accepted, $files['detail'] ?? null, (int) filesize($files['video']));
            $committed = true;
            $this->transcribe($video->fresh() ?? $video);
        } catch (YouTubeRenderException $e) {
            if (! $committed) {
                $this->discard($video);
                $this->block($video, $status, $e->getMessage());
            }
        } catch (Throwable $e) {
            Log::warning('YouTube render failed', [
                'user_id' => $video->user_id,
                'video_id' => $video->id,
                'error' => $e::class,
            ]);
            if (! $committed) {
                $this->discard($video);
                $this->block($video, $status, 'The local file could not be made. Nothing is uploaded.');
            }
        } finally {
            $this->removeDir($work);
        }

        $fresh = $video->fresh() ?? $video;
        if ($fresh->status !== $status) {
            $fresh->update(['status' => $status]);
        }

        return $fresh->fresh() ?? $fresh;
    }

    private function block(YouTubeVideo $video, string $status, string $reason): YouTubeVideo
    {
        $fresh = $video->fresh() ?? $video;
        $package = is_array($fresh->package) ? $fresh->package : [];
        $render = is_array($package['render'] ?? null) ? $package['render'] : [];
        $this->forgetMedia($render);
        $kind = (string) ($render['kind'] ?? $this->renderer->kind($fresh));
        $package['render'] = [
            'provider' => 'pending',
            'ready' => false,
            'kind' => $kind,
            'note' => $reason,
            'attempts' => (int) ($render['attempts'] ?? 0) + 1,
        ];
        $fresh->update([
            'status' => $status,
            'package' => $package,
        ]);

        return $fresh->fresh() ?? $fresh;
    }

    /**
     * @param  array{video: string, preview: string, poster: string}  $files
     * @return array{video: string, preview: string, poster: string}
     */
    private function store(YouTubeVideo $video, array $files): array
    {
        $keys = [
            'video' => $video->user_id.'/'.$video->id.'/render.mp4',
            'preview' => $video->user_id.'/'.$video->id.'/preview.mp4',
            'poster' => $video->user_id.'/'.$video->id.'/poster.jpg',
        ];
        foreach (['video' => $files['video'], 'preview' => $files['preview'], 'poster' => $files['poster']] as $name => $path) {
            $in = fopen($path, 'rb');
            if ($in === false) {
                throw new YouTubeRenderException('The local file could not be stored. Nothing is uploaded.');
            }
            try {
                $this->media->putStream($keys[$name], $in, MediaStore::YOUTUBE);
            } finally {
                fclose($in);
            }
        }

        return $keys;
    }

    /**
     * @param  array{video: string, preview: string, poster: string}  $keys
     * @param  array{format: string, video_codec: string, audio_codec: string, duration: float, width: int, height: int}  $accepted
     */
    private function commit(YouTubeVideo $video, string $status, array $keys, array $accepted, ?string $detail, int $bytes): void
    {
        $fresh = YouTubeVideo::query()->whereKey($video->id)->first();
        if (! $fresh || $fresh->status !== $status) {
            foreach ($keys as $key) {
                try {
                    $this->media->delete($key, MediaStore::YOUTUBE);
                } catch (Throwable) {
                    // A missing object is already gone.
                }
            }
            throw new YouTubeRenderException('This video is not waiting for a local file.');
        }

        $package = is_array($fresh->package) ? $fresh->package : [];
        $previous = is_array($package['render'] ?? null) ? $package['render'] : [];
        $note = 'Local file is ready to preview or download. Nothing has been uploaded.';
        if (is_string($detail) && $detail !== '') {
            $note .= ' '.$detail;
        }
        $package['render'] = [
            'provider' => 'ffmpeg',
            'ready' => true,
            'kind' => (string) ($previous['kind'] ?? $this->renderer->kind($fresh)),
            'note' => $note,
            'attempts' => (int) ($previous['attempts'] ?? 0) + 1,
            'disk' => self::DISK,
            'path' => $keys['video'],
            'preview_path' => $keys['preview'],
            'poster_path' => $keys['poster'],
            'extension' => 'mp4',
            'bytes' => $bytes > 0 ? $bytes : null,
            'duration_seconds' => round($accepted['duration'], 2),
            'width' => $accepted['width'],
            'height' => $accepted['height'],
            'video_codec' => $accepted['video_codec'],
            'audio_codec' => $accepted['audio_codec'],
        ];
        $fresh->update([
            'status' => $status,
            'package' => $package,
        ]);
    }

    private function transcribe(YouTubeVideo $video): void
    {
        $fresh = $video->fresh() ?? $video;
        $package = is_array($fresh->package) ? $fresh->package : [];
        $render = is_array($package['render'] ?? null) ? $package['render'] : [];
        if (($render['ready'] ?? false) !== true || ! is_string($render['path'] ?? null) || $render['path'] === '') {
            return;
        }

        $package['transcription'] = [
            'provider' => $this->transcriber->key(),
            'available' => $this->transcriber->available(),
            'note' => 'No local transcription provider is configured. Paste a transcript to cut Shorts. The YouTube Caption API is not used.',
        ];
        if (! $this->transcriber->available()) {
            $fresh->update(['package' => $package]);

            return;
        }

        $local = null;
        try {
            $local = $this->media->materialize(self::DISK, $render['path']);
            $text = $this->transcriber->transcribe($local->path);
            $this->engine->storeTranscript($fresh, $text);
            $fresh = $fresh->fresh() ?? $fresh;
            $package = is_array($fresh->package) ? $fresh->package : [];
            $package['transcription'] = [
                'provider' => $this->transcriber->key(),
                'available' => true,
                'note' => 'Shorts were cut again from the local transcript. Nothing was sent to YouTube.',
            ];
        } catch (Throwable $e) {
            Log::warning('YouTube transcription failed', [
                'user_id' => $fresh->user_id,
                'video_id' => $fresh->id,
                'error' => $e::class,
            ]);
            $package['transcription'] = [
                'provider' => $this->transcriber->key(),
                'available' => true,
                'note' => 'The local transcript could not be read. Paste a transcript to cut Shorts. The YouTube Caption API is not used.',
            ];
        } finally {
            if ($local?->temporary && is_file($local->path)) {
                unlink($local->path);
            }
        }

        $fresh->update([
            'status' => $fresh->status,
            'package' => $package,
        ]);
    }

    /**
     * @param  array<string, mixed>  $render
     */
    private function forgetMedia(array $render): void
    {
        foreach (['path', 'preview_path', 'poster_path'] as $field) {
            $key = $render[$field] ?? null;
            if (is_string($key) && $key !== '') {
                try {
                    $this->media->delete($key, MediaStore::YOUTUBE);
                } catch (Throwable) {
                    // A missing object is already gone.
                }
            }
        }
    }

    private function discard(YouTubeVideo $video): void
    {
        try {
            $this->media->deletePrefix($video->user_id.'/'.$video->id, MediaStore::YOUTUBE);
        } catch (Throwable) {
            // Nothing was stored.
        }
    }

    private function workDir(int $id): string
    {
        $dir = storage_path('app/youtube-work/'.$id.'-'.bin2hex(random_bytes(4)));
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new YouTubeRenderException('A local work folder could not be opened. Nothing is uploaded.');
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function sweepWork(): void
    {
        $root = storage_path('app/youtube-work');
        if (! is_dir($root)) {
            return;
        }
        $items = scandir($root);
        if ($items === false) {
            return;
        }
        $cutoff = time() - (6 * 3600);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $root.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && filemtime($path) !== false && filemtime($path) < $cutoff) {
                $this->removeDir($path);
            }
        }
    }
}
