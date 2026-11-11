<?php

namespace App\Services\TikTok;

use App\Jobs\RenderClipJob;
use App\Models\TikTokClip;
use App\Models\TikTokClipVideo;
use App\Models\User;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\Storage\MediaStore;
use App\Support\GeminiCatalog;
use App\Support\SpeechDrivers;
use App\Support\TikTokCatalog;
use App\Support\TikTokContentPosting;
use App\Support\TikTokVideoCatalog;
use App\Support\TikTokWeek;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TikTokRenderService
{
    public function __construct(
        private readonly TikTokRenderState $state,
        private readonly SceneBuilder $scenes,
        private readonly MotionGraphicsProvider $motion,
        private readonly GeminiVideoProvider $gemini,
        private readonly ApiSpendService $budget,
        private readonly MediaStore $media,
        private readonly TikTokVideoService $videos,
        private readonly TikTokVideoProbe $probe,
    ) {}

    public function dispatch(TikTokClip $clip, bool $force = false): void
    {
        try {
            $clip->loadMissing('video', 'user');
            if (! in_array($clip->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)) {
                return;
            }
            if ($this->userVideo($clip)) {
                return;
            }
            $status = (string) ($clip->package['render']['status'] ?? TikTokCatalog::RENDER_NONE);
            if (! $force && in_array($status, [TikTokCatalog::RENDER_QUEUED, TikTokCatalog::RENDER_RENDERING], true)) {
                return;
            }
            $reason = $this->guardReason($clip);
            if ($reason !== null) {
                $this->skipQuietly($clip, $reason);

                return;
            }
            $regeneration = in_array($status, [TikTokCatalog::RENDER_READY, TikTokCatalog::RENDER_FAILED], true);
            $this->state->queue($clip, $regeneration);
            RenderClipJob::dispatch($clip->id);
        } catch (Throwable $e) {
            Log::warning('TikTok render was not queued', [
                'user_id' => $clip->user_id,
                'error' => $e::class,
            ]);
            $fresh = $clip->fresh() ?? $clip;
            $current = (string) ($fresh->package['render']['status'] ?? TikTokCatalog::RENDER_NONE);
            if ($current === TikTokCatalog::RENDER_QUEUED) {
                try {
                    $this->state->fail($fresh, TikTokCatalog::RENDER_BUSY);
                } catch (Throwable) {
                    // The clip stays queued. The sweeper will mark it failed.
                }
            }
        }
    }

    /**
     * Why a video would not be made. No side effects.
     */
    public function explain(TikTokClip $clip): ?string
    {
        $clip->loadMissing('video', 'user');
        if (! in_array($clip->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)) {
            return 'This clip is not waiting for a video.';
        }

        return $this->guardReason($clip);
    }

    public function render(TikTokClip $clip): void
    {
        $work = new TikTokWorkspace;
        $stored = [];
        try {
            $clip->loadMissing('video', 'user');
            if ($this->userVideo($clip) || ! in_array($clip->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)) {
                return;
            }
            $this->state->start($clip->fresh() ?? $clip);
            $user = $clip->user;
            if (! $user) {
                $this->state->fail($clip->fresh() ?? $clip, TikTokCatalog::RENDER_BUSY);

                return;
            }
            $preferences = TikTokVideoPreferences::forUser($user);
            $enabled = $preferences->enabledContext();
            $started = $clip->fresh() ?? $clip;
            $spec = $this->scenes->spec($started, $preferences, $enabled);
            $spec['captions'] = (bool) $preferences->toArray()['captions']['enabled'];
            $spec['music'] = $preferences->musicEnabled() ? TikTokVideoPreferences::licensedMusic() : [];
            $directory = $work->begin($clip->id);
            $format = (string) ($started->package['week']['format'] ?? TikTokWeek::MOTION);
            $files = $format === TikTokWeek::SCENE
                ? app(KlingSceneRenderer::class)->render($user, $directory, $spec, $this->speech())
                : $this->picture($user, $directory, $spec);
            $stored = $this->store($clip->fresh() ?? $clip, $user, $files);
        } catch (Throwable $e) {
            Log::warning('TikTok render failed', [
                'user_id' => $clip->user_id,
                'clip_id' => $clip->id,
                'error' => $e::class,
            ]);
            $this->deleteKeys($stored);
            $fresh = $clip->fresh() ?? $clip;
            $message = $e->getMessage();
            $plain = in_array($message, [
                TikTokCatalog::RENDER_VOICE_UNAVAILABLE,
                TikTokCatalog::RENDER_BUSY,
                TikTokCatalog::RENDER_SCENE_UNAVAILABLE,
                TikTokCatalog::RENDER_SCENE_FAILED,
                GeminiCatalog::FAILED,
                GeminiCatalog::QUOTA,
            ], true) ? $message : GeminiCatalog::FAILED;
            try {
                $this->state->fail($fresh, $plain);
            } catch (Throwable) {
                // Already terminal.
            }
        } finally {
            $work->cleanup();
        }
    }

    public function sweep(): int
    {
        $changed = 0;
        TikTokClip::query()->orderBy('id')->each(function (TikTokClip $clip) use (&$changed) {
            $render = $clip->package['render'] ?? [];
            $status = (string) ($render['status'] ?? '');
            $queuedAt = $render['queued_at'] ?? null;
            $startedAt = $render['started_at'] ?? null;
            $staleQueued = $status === TikTokCatalog::RENDER_QUEUED && is_string($queuedAt) && now()->subMinutes(30)->greaterThan($queuedAt);
            $staleRendering = $status === TikTokCatalog::RENDER_RENDERING && is_string($startedAt) && now()->subMinutes(15)->greaterThan($startedAt);
            if (! $staleQueued && ! $staleRendering) {
                return;
            }
            try {
                $this->state->fail($clip, TikTokCatalog::RENDER_BUSY);
                $changed++;
            } catch (Throwable) {
                // Another worker already moved it.
            }
        });
        app(TikTokWorkspace::class)->sweep();

        return $changed;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [
            TikTokCatalog::RENDER_NONE => 0,
            TikTokCatalog::RENDER_QUEUED => 0,
            TikTokCatalog::RENDER_RENDERING => 0,
            TikTokCatalog::RENDER_READY => 0,
            TikTokCatalog::RENDER_FAILED => 0,
            TikTokCatalog::RENDER_SKIPPED => 0,
        ];
        TikTokClip::query()->select(['id', 'package'])->orderBy('id')->each(function (TikTokClip $clip) use (&$counts) {
            $status = (string) ($clip->package['render']['status'] ?? TikTokCatalog::RENDER_NONE);
            if (! isset($counts[$status])) {
                $status = TikTokCatalog::RENDER_NONE;
            }
            $counts[$status]++;
        });

        return $counts;
    }

    public function oldestQueuedAgeMinutes(): ?int
    {
        $oldest = null;
        TikTokClip::query()->select(['id', 'package'])->orderBy('id')->each(function (TikTokClip $clip) use (&$oldest) {
            $render = $clip->package['render'] ?? [];
            if (($render['status'] ?? '') !== TikTokCatalog::RENDER_QUEUED || ! is_string($render['queued_at'] ?? null)) {
                return;
            }
            $at = strtotime($render['queued_at']);
            if ($at !== false && ($oldest === null || $at < $oldest)) {
                $oldest = $at;
            }
        });

        return $oldest === null ? null : (int) floor((time() - $oldest) / 60);
    }

    private function guardReason(TikTokClip $clip): ?string
    {
        if ($this->userVideo($clip)) {
            return null;
        }
        $user = $clip->user;
        if (! $user || $user->is_blocked) {
            return 'This account cannot make a video right now.';
        }
        $preferences = TikTokVideoPreferences::forUser($user);
        $format = (string) ($clip->package['week']['format'] ?? TikTokWeek::MOTION);
        if ($format === TikTokWeek::SCENE && ! app(KlingVideoClient::class)->configured()) {
            return TikTokCatalog::RENDER_SCENE_UNAVAILABLE;
        }
        if ($preferences->type() !== TikTokVideoCatalog::TYPE_MOTION || ! TikTokVideoCatalog::typeAvailable($preferences->type())) {
            return 'This video style is not available yet.';
        }
        if ($preferences->language() !== TikTokVideoCatalog::LANGUAGE_EN) {
            return 'This language is not available yet.';
        }
        if (! $this->budget->canSpend($user->id, 10)) {
            return TikTokCatalog::RENDER_VOICE_UNAVAILABLE;
        }

        return null;
    }

    private function skipQuietly(TikTokClip $clip, string $reason): void
    {
        if ($this->userVideo($clip)) {
            return;
        }
        try {
            $this->state->skip($clip, $reason);
        } catch (Throwable) {
            // A render is already moving. Leave it.
        }
    }

    private function userVideo(TikTokClip $clip): bool
    {
        $video = $clip->video;

        return $video !== null && $video->ready && $video->source === 'user' && $video->purged_at === null;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{video: string, preview: string, poster: string}
     */
    private function picture(User $user, string $directory, array $spec): array
    {
        $picture = (string) config('tiktok_video.picture', 'motion');
        if ($picture === 'gemini') {
            return $this->gemini->render($user, $directory, $spec);
        }
        if ($picture === 'motion' || $picture === '') {
            return $this->motion->render($user, $directory, $spec, $this->speech());
        }
        Log::warning('Unknown TIKTOK_VIDEO_PROVIDER');

        throw new RuntimeException(GeminiCatalog::FAILED);
    }

    private function speech(): SpeechProvider
    {
        return SpeechDrivers::make((string) config('tiktok_video.tts_driver', SpeechDrivers::FAKE));
    }

    /**
     * Store-time re-check: a discard or a user upload wins over this render.
     *
     * @param  array{video: string, preview: string, poster: string}  $files
     * @return array<string, string>
     */
    private function store(TikTokClip $clip, User $user, array $files): array
    {
        $keys = [
            'video' => $user->id.'/'.$clip->id.'/source.mp4',
            'preview' => $user->id.'/'.$clip->id.'/preview.mp4',
            'poster' => $user->id.'/'.$clip->id.'/poster.jpg',
        ];
        foreach (['video' => $files['video'], 'preview' => $files['preview'], 'poster' => $files['poster']] as $name => $path) {
            $in = fopen($path, 'rb');
            if ($in === false) {
                throw new RuntimeException("We couldn't make the video. Try again, or add your own.");
            }
            try {
                $this->media->putStream($keys[$name], $in);
            } finally {
                fclose($in);
            }
        }

        $keep = false;
        try {
            DB::transaction(function () use ($clip, $files, $keys, &$keep) {
                $fresh = TikTokClip::query()->whereKey($clip->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->status === TikTokCatalog::DISCARDED) {
                    return;
                }
                $fresh->load('video');
                if ($fresh->video && $fresh->video->source === 'user' && $fresh->video->ready && $fresh->video->purged_at === null) {
                    return;
                }
                $probed = $this->videos->acceptProbe($this->probe->inspect($files['video']));
                $previous = $fresh->video;
                if ($previous) {
                    $previous->forceFill(['path' => null, 'preview_path' => null, 'poster_path' => null, 'purged_at' => now()])->save();
                    $previous->delete();
                }
                TikTokClipVideo::query()->create([
                    'clip_id' => $fresh->id,
                    'source' => 'generated',
                    'disk' => TikTokContentPosting::DISK,
                    'path' => $keys['video'],
                    'preview_path' => $keys['preview'],
                    'poster_path' => $keys['poster'],
                    'preview_bytes' => filesize($files['preview']) ?: null,
                    'original_filename' => 'clip.mp4',
                    'size' => filesize($files['video']) ?: 0,
                    'duration_seconds' => $probed['duration'],
                    'width' => $probed['width'],
                    'height' => $probed['height'],
                    'codec' => 'h264',
                    'checksum' => hash_file('sha256', $files['video']) ?: null,
                    'ready' => true,
                    'aspect_warning' => $probed['warning'],
                ]);
                $this->state->succeed($fresh);
                $keep = true;
            });
        } finally {
            if (! $keep) {
                $this->deleteKeys($keys);
            }
        }

        if ($keep) {
            $fresh = $clip->fresh();
            if ($fresh && $fresh->status === TikTokCatalog::QUEUED) {
                app(TikTokDraftService::class)->dispatchIfReady($fresh);
            }
        }

        return $keep ? $keys : [];
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function deleteKeys(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->media->delete($key);
            } catch (Throwable) {
                // A missing object is already gone.
            }
        }
    }
}
