<?php

namespace App\Services\YouTube;

use App\Exceptions\YouTubeOAuthException;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\YouTubeVideo;
use App\Services\Storage\MediaStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class YouTubeUploadService
{
    public function __construct(
        private readonly YouTubeUploadClient $client,
        private readonly YouTubeUploadConsentService $consent,
        private readonly YouTubeAllowanceService $allowance,
        private readonly YouTubeInsertBudget $budget,
        private readonly YouTubePrivacy $privacy,
        private readonly YouTubeOAuthService $oauth,
        private readonly YouTubeEngineService $engine,
        private readonly MediaStore $media,
    ) {}

    public function attempt(User $user, YouTubeVideo $video, ?string $desiredPrivacy = null): YouTubeVideo
    {
        if ((int) $video->user_id !== (int) $user->id) {
            throw (new ModelNotFoundException)->setModel(YouTubeVideo::class, [$video->id]);
        }

        $video = $video->fresh() ?? $video;
        if ($video->status === 'posted' || filled($video->posted_video_id)) {
            throw ValidationException::withMessages([
                'upload' => ['This video is already posted.'],
            ]);
        }

        if ($video->status !== 'queued') {
            $note = $video->status === 'waiting'
                ? 'Ship it approves this package. It does not post it. Approve it before sending.'
                : 'This video is not approved for upload. Nothing was posted.';

            return $this->stop($video, 'failed', $note, false);
        }

        $package = is_array($video->package) ? $video->package : [];
        $render = is_array($package['render'] ?? null) ? $package['render'] : [];
        if (($render['ready'] ?? false) !== true) {
            return $this->stop($video, 'failed', 'The local file is not ready. Make it before sending. Nothing was posted.', true);
        }

        $path = $render['path'] ?? null;
        $extension = strtolower((string) ($render['extension'] ?? 'mp4'));
        if (! is_string($path) || $path === '' || ! Storage::disk(YouTubeRenderService::DISK)->exists($path)) {
            return $this->stop($video, 'failed', 'The ready file is missing on this server. Make it again. Nothing was posted.', true);
        }
        $mime = $this->mime($extension);
        if ($mime === null) {
            return $this->stop($video, 'failed', 'The local file is not an mp4, mov, or webm. Nothing was posted.', true);
        }

        $account = $this->account($user);
        if (! $account || ! $account->is_active) {
            return $this->stop($video, 'failed', 'Connect YouTube before sending. This file stays on this server.', true);
        }
        if (! $this->consent->granted($account)) {
            return $this->stop($video, 'failed', 'YouTube is still read-only. Allow uploads in Settings. This file stays on this server.', true);
        }

        $auditApproved = (bool) config('services.youtube.upload_audit_approved');
        $decision = $this->privacy->decide($auditApproved, $desiredPrivacy);
        if ($decision['action'] === 'hold') {
            return $this->stop($video, 'held', $decision['note'], false);
        }

        $allowance = $this->allowance->status($user);
        if (! $allowance['plan_found']) {
            return $this->stop($video, 'failed', 'YouTube uploads need an active plan. This file stays queued.', true);
        }
        if ($allowance['remaining'] < 1) {
            $renews = substr($allowance['period_end'], 0, 10);

            return $this->stop(
                $video,
                'failed',
                "You've used all {$allowance['limit']} YouTube videos for this month. The allowance renews on {$renews}. This file stays queued.",
                true,
            );
        }

        $lock = Cache::lock('youtube-insert-budget', 20);
        if (! $lock->get()) {
            return $this->stop($video, 'queued', 'Another upload is using the shared daily budget. This file stays queued. Try again in a moment.', true);
        }

        try {
            try {
                $account = $this->oauth->ensureFresh($account);
            } catch (YouTubeOAuthException) {
                return $this->stop($video, 'failed', 'YouTube needs you to sign in again before sending. This file stays queued.', true);
            }
            if (! $this->consent->granted($account)) {
                return $this->stop($video, 'failed', 'YouTube is still read-only. Allow uploads in Settings. This file stays on this server.', true);
            }

            if (! $this->budget->reserve($user, $video)) {
                $next = $this->budget->resetsOn();

                return $this->stop(
                    $video,
                    'queued',
                    "The shared upload budget for today is used. This video stays queued and can be sent again on {$next}.",
                    true,
                    $next,
                );
            }

            try {
                $local = $this->media->materialize(YouTubeRenderService::DISK, $path);
                try {
                    $result = $this->client->insert(
                        (string) $account->access_token,
                        $local->path,
                        $mime,
                        [
                            'title' => $this->title($video),
                            'description' => mb_substr(trim((string) $video->description), 0, 5000),
                            'tags' => $this->tags($package),
                        ],
                        (string) $decision['privacy'],
                    );
                } finally {
                    $local->cleanup();
                }
            } catch (YouTubeUploadException $e) {
                return $this->stop($video, 'failed', $e->getMessage(), true);
            } catch (\Throwable) {
                return $this->stop($video, 'failed', 'YouTube did not accept the video. It stays queued and can be sent again.', true);
            }

            if (! $this->privacy->acceptable($auditApproved, $result['privacy'])) {
                return $this->stop(
                    $video,
                    'failed',
                    'YouTube applied a visibility this server will not claim while the upload audit is off. The package stays queued and was not marked posted.',
                    false,
                );
            }

            try {
                $posted = $this->markPosted($video, $result['id'], $result['privacy'], $decision['note']);
            } catch (YouTubeUploadException $e) {
                return $this->stop($video, 'failed', $e->getMessage(), false);
            }
            $this->refreshCaptions($account, $posted);

            return $posted->fresh() ?? $posted;
        } finally {
            $lock->release();
        }
    }

    private function refreshCaptions(ConnectedAccount $account, YouTubeVideo $video): void
    {
        $videoId = (string) $video->posted_video_id;
        if ($video->status !== 'posted' || $videoId === '' || $video->posted_privacy === null) {
            return;
        }

        try {
            $text = $this->client->captions((string) $account->access_token, $videoId);
        } catch (\Throwable) {
            return;
        }
        if ($text === null || trim($text) === '') {
            return;
        }

        $this->engine->storeTranscript($video, $text);
    }

    private function markPosted(YouTubeVideo $video, string $videoId, string $privacy, string $note): YouTubeVideo
    {
        $fresh = $video->fresh() ?? $video;
        if ($fresh->status !== 'queued') {
            throw new YouTubeUploadException('This video is not approved for upload. Nothing was posted.');
        }

        $package = is_array($fresh->package) ? $fresh->package : [];
        $package['upload'] = [
            'state' => 'posted',
            'retryable' => false,
            'note' => $note,
            'privacy' => $privacy,
            'youtube_video_id' => $videoId,
            'next_attempt_on' => null,
        ];
        $fresh->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_video_id' => $videoId,
            'posted_privacy' => $privacy,
            'package' => $package,
        ]);

        return $fresh->fresh() ?? $fresh;
    }

    private function stop(
        YouTubeVideo $video,
        string $state,
        string $note,
        bool $retryable,
        ?string $nextAttemptOn = null,
    ): never {
        $fresh = $video->fresh() ?? $video;
        $package = is_array($fresh->package) ? $fresh->package : [];
        $package['upload'] = [
            'state' => $state,
            'retryable' => $retryable,
            'note' => $note,
            'privacy' => null,
            'youtube_video_id' => null,
            'next_attempt_on' => $nextAttemptOn,
        ];
        $fresh->update(['package' => $package]);

        throw ValidationException::withMessages([
            'upload' => [$note],
        ]);
    }

    private function account(User $user): ?ConnectedAccount
    {
        return ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->first();
    }

    private function title(YouTubeVideo $video): string
    {
        $title = trim((string) ($video->title ?: $video->topic));
        if ($title === '') {
            $title = 'Untitled video';
        }

        return mb_substr($title, 0, 100);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private function tags(array $package): array
    {
        $tags = [];
        foreach ($package['tags'] ?? [] as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $tags[] = mb_substr($tag, 0, 30);
            if (count($tags) === 15) {
                break;
            }
        }

        return $tags;
    }

    private function mime(string $extension): ?string
    {
        return match ($extension) {
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => null,
        };
    }
}
