<?php

namespace App\Services\TikTok;

use App\Exceptions\TikTokActionException;
use App\Exceptions\TikTokVideoException;
use App\Models\TikTokClip;
use App\Models\TikTokClipVideo;
use App\Models\User;
use App\Support\TikTokCatalog;
use App\Support\TikTokContentPosting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TikTokVideoService
{
    public function __construct(private readonly TikTokVideoProbe $probe) {}

    /**
     * @return array{chunk_bytes: int, chunk_count: int}
     */
    public function start(User $user, TikTokClip $clip, string $filename, int $size): array
    {
        $this->acceptsFile($clip);
        if ($size < 1) {
            throw new TikTokVideoException('Choose a video file.');
        }
        if ($size > TikTokContentPosting::MAX_BYTES) {
            throw new TikTokVideoException('This file is too large. Mackie17 accepts videos up to 100 MB.');
        }

        $chunks = (int) ceil($size / TikTokContentPosting::CHUNK_BYTES);
        $this->clearParts($user->id, $clip->id);
        Cache::put($this->sessionKey($clip->id), [
            'user_id' => $user->id,
            'filename' => $this->displayName($filename),
            'size' => $size,
            'chunks' => $chunks,
        ], now()->addHours(6));

        return [
            'chunk_bytes' => TikTokContentPosting::CHUNK_BYTES,
            'chunk_count' => $chunks,
        ];
    }

    public function storeChunk(User $user, TikTokClip $clip, int $index, string $body): int
    {
        $this->acceptsFile($clip);
        $session = $this->session($clip, $user);
        if ($index < 0 || $index >= $session['chunks']) {
            throw new TikTokVideoException('This upload was incomplete. Try again.');
        }

        $expected = $index === $session['chunks'] - 1
            ? $session['size'] - (TikTokContentPosting::CHUNK_BYTES * ($session['chunks'] - 1))
            : TikTokContentPosting::CHUNK_BYTES;
        if (strlen($body) !== $expected) {
            throw new TikTokVideoException('This upload was incomplete. Try again.');
        }

        Storage::disk(TikTokContentPosting::DISK)->put($this->partPath($user->id, $clip->id, $index), $body);

        return $index;
    }

    public function complete(User $user, TikTokClip $clip): TikTokClip
    {
        $this->acceptsFile($clip);
        $session = $this->session($clip, $user);
        $disk = Storage::disk(TikTokContentPosting::DISK);
        $final = $user->id.'/'.$clip->id.'/source.mp4';
        $incoming = $user->id.'/'.$clip->id.'/incoming.mp4';
        $absolute = $disk->path($incoming);
        $directory = dirname($absolute);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new TikTokVideoException("We couldn't store this video. Try again.");
        }

        $out = fopen($absolute, 'wb');
        if ($out === false) {
            throw new TikTokVideoException("We couldn't store this video. Try again.");
        }

        try {
            for ($index = 0; $index < $session['chunks']; $index++) {
                $part = $this->partPath($user->id, $clip->id, $index);
                if (! $disk->exists($part)) {
                    throw new TikTokVideoException('This upload was incomplete. Try again.');
                }
                $in = fopen($disk->path($part), 'rb');
                if ($in === false) {
                    throw new TikTokVideoException('This upload was incomplete. Try again.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } catch (TikTokVideoException $e) {
            fclose($out);
            $disk->delete($incoming);
            throw $e;
        }
        fclose($out);

        $bytes = filesize($absolute);
        if ($bytes !== $session['size']) {
            $disk->delete($incoming);
            throw new TikTokVideoException('This upload was incomplete. Try again.');
        }

        try {
            $accepted = $this->accept($this->probe->inspect($absolute));
        } catch (TikTokVideoException $e) {
            $disk->delete($incoming);
            $this->clearParts($user->id, $clip->id);
            Cache::forget($this->sessionKey($clip->id));
            throw $e;
        }

        $checksum = hash_file('sha256', $absolute) ?: null;
        $oldPath = null;

        try {
            DB::transaction(function () use ($clip, $session, $incoming, $checksum, $accepted, &$oldPath) {
                $fresh = TikTokClip::query()->whereKey($clip->id)->lockForUpdate()->first();
                if (! $fresh || ! in_array($fresh->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)) {
                    throw new TikTokVideoException('Only a clip that is waiting or confirmed can take a video.');
                }

                $previous = $fresh->video;
                if ($previous) {
                    $oldPath = $previous->path;
                    $previous->forceFill(['path' => null, 'purged_at' => now()])->save();
                    $previous->delete();
                }

                TikTokClipVideo::query()->create([
                    'clip_id' => $fresh->id,
                    'source' => 'user',
                    'disk' => TikTokContentPosting::DISK,
                    'path' => $incoming,
                    'original_filename' => $session['filename'],
                    'size' => $session['size'],
                    'duration_seconds' => $accepted['duration'],
                    'width' => $accepted['width'],
                    'height' => $accepted['height'],
                    'codec' => 'h264',
                    'checksum' => $checksum,
                    'ready' => true,
                    'aspect_warning' => $accepted['warning'],
                ]);

                $package = $fresh->package;
                $package['render']['ready'] = true;
                $package['render']['provider'] = 'user';
                $fresh->package = $package;
                $fresh->save();
            });
        } catch (TikTokVideoException $e) {
            $disk->delete($incoming);
            throw $e;
        }

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $final) {
            $disk->delete($oldPath);
        }
        if ($disk->exists($final)) {
            $disk->delete($final);
        }
        $disk->move($incoming, $final);
        TikTokClipVideo::query()->where('clip_id', $clip->id)->update(['path' => $final]);

        $this->clearParts($user->id, $clip->id);
        Cache::forget($this->sessionKey($clip->id));

        $saved = $clip->fresh() ?? $clip;
        if ($saved->status === TikTokCatalog::QUEUED) {
            app(TikTokDraftService::class)->dispatchIfReady($saved);
        }

        return $saved->fresh() ?? $saved;
    }

    public function stream(User $user, TikTokClip $clip): string
    {
        $video = $clip->video;
        if (! $video || ! $video->ready || ! is_string($video->path) || $video->path === '' || $video->purged_at !== null) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(TikTokClipVideo::class);
        }
        if ((int) $clip->user_id !== (int) $user->id) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(TikTokClip::class, [$clip->id]);
        }

        $disk = Storage::disk($video->disk ?: TikTokContentPosting::DISK);
        if (! $disk->exists($video->path)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(TikTokClipVideo::class);
        }

        return $disk->path($video->path);
    }

    public function deleteForClip(TikTokClip $clip): void
    {
        $clip->video?->delete();
        $this->clearParts($clip->user_id, $clip->id);
        Cache::forget($this->sessionKey($clip->id));
        $this->markFileMissing($clip);
    }

    public function deleteForUser(int $userId): void
    {
        $clips = TikTokClip::query()->where('user_id', $userId)->with('video')->get();
        foreach ($clips as $clip) {
            $this->deleteForClip($clip);
        }
        Storage::disk(TikTokContentPosting::DISK)->deleteDirectory((string) $userId);
    }

    public function sweep(): void
    {
        $disk = Storage::disk(TikTokContentPosting::DISK);

        TikTokClip::query()
            ->where('status', TikTokCatalog::IN_DRAFTS)
            ->whereNotNull('drafted_at')
            ->where('drafted_at', '<=', now()->subDays(TikTokContentPosting::RETENTION_DAYS))
            ->with('video')
            ->each(function (TikTokClip $clip) use ($disk) {
                $video = $clip->video;
                if (! $video || $video->purged_at !== null) {
                    return;
                }
                if (is_string($video->path) && $video->path !== '') {
                    $disk->delete($video->path);
                }
                $video->forceFill(['path' => null, 'purged_at' => now()])->save();
            });

        TikTokClipVideo::query()
            ->where('ready', true)
            ->whereNull('purged_at')
            ->with('clip')
            ->each(function (TikTokClipVideo $video) use ($disk) {
                if (! is_string($video->path) || $video->path === '' || ! $disk->exists($video->path)) {
                    $video->forceFill(['ready' => false])->save();
                    if ($video->clip) {
                        $this->markFileMissing($video->clip);
                    }
                }
            });

        $known = TikTokClipVideo::query()->whereNotNull('path')->pluck('path')->all();
        foreach ($disk->allFiles() as $file) {
            if (str_contains($file, '/parts/')) {
                continue;
            }
            if (! in_array($file, $known, true)) {
                $disk->delete($file);
            }
        }
    }

    private function acceptsFile(TikTokClip $clip): void
    {
        if (! in_array($clip->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)) {
            throw new TikTokActionException(
                TikTokCatalog::ERROR_INVALID_STATE,
                'Only a clip that is waiting or confirmed can take a video.',
            );
        }
    }

    /**
     * @param  array{format: string, video_codec: string, audio_codec: string, duration: float, width: int, height: int}  $probe
     * @return array{duration: int, width: int, height: int, warning: ?string}
     */
    private function accept(array $probe): array
    {
        $format = strtolower($probe['format']);
        $container = str_contains($format, 'mp4') || str_contains($format, 'mov') || str_contains($format, 'quicktime');
        $video = strtolower($probe['video_codec']);
        $audio = strtolower($probe['audio_codec']);
        if (! $container || $video !== 'h264' || $audio !== 'aac') {
            throw new TikTokVideoException('This video needs to be an MP4 or MOV with H.264 video and AAC audio.');
        }

        $duration = (int) ceil($probe['duration']);
        if ($duration < 1) {
            throw new TikTokVideoException('This video needs to be an MP4 or MOV with H.264 video and AAC audio.');
        }
        if ($duration > TikTokContentPosting::MAX_DURATION_SECONDS) {
            $minutes = (int) ceil($duration / 60);
            $unit = $minutes === 1 ? 'minute' : 'minutes';
            throw new TikTokVideoException("This file is {$minutes} {$unit} long. TikTok clips from Mackie17 can be up to 10 minutes.");
        }

        $warning = null;
        if ($probe['width'] > 0 && $probe['height'] > 0) {
            $ratio = $probe['width'] / $probe['height'];
            $target = 9 / 16;
            if (abs($ratio - $target) / $target > 0.08) {
                $warning = 'This video is not 9:16. TikTok can still take it. A vertical video fits the feed better.';
            }
        }

        return [
            'duration' => $duration,
            'width' => $probe['width'],
            'height' => $probe['height'],
            'warning' => $warning,
        ];
    }

    /**
     * @return array{user_id: int, filename: string, size: int, chunks: int}
     */
    private function session(TikTokClip $clip, User $user): array
    {
        $session = Cache::get($this->sessionKey($clip->id));
        if (! is_array($session) || (int) ($session['user_id'] ?? 0) !== (int) $user->id) {
            throw new TikTokVideoException('Start the upload again.');
        }

        return [
            'user_id' => (int) $session['user_id'],
            'filename' => (string) $session['filename'],
            'size' => (int) $session['size'],
            'chunks' => (int) $session['chunks'],
        ];
    }

    private function markFileMissing(TikTokClip $clip): void
    {
        $package = $clip->package;
        if (($package['render']['ready'] ?? false) !== true) {
            return;
        }
        $package['render']['ready'] = false;
        $clip->package = $package;
        $clip->save();
    }

    private function clearParts(int $userId, int $clipId): void
    {
        Storage::disk(TikTokContentPosting::DISK)->deleteDirectory($userId.'/'.$clipId.'/parts');
    }

    private function partPath(int $userId, int $clipId, int $index): string
    {
        return $userId.'/'.$clipId.'/parts/'.$index;
    }

    private function sessionKey(int $clipId): string
    {
        return 'tiktok-video-upload.'.$clipId;
    }

    private function displayName(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $clean = preg_replace('/[^\w.\- ]+/u', '', $base) ?: 'video.mp4';

        return mb_substr($clean, 0, 180);
    }
}
