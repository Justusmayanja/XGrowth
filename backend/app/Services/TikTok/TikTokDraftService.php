<?php

namespace App\Services\TikTok;

use App\Exceptions\TikTokActionException;
use App\Exceptions\TikTokOAuthException;
use App\Jobs\SendTikTokDraft;
use App\Models\ConnectedAccount;
use App\Models\TikTokClip;
use App\Models\User;
use App\Support\TikTokCatalog;
use App\Support\TikTokContentPosting;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TikTokDraftService
{
    public function __construct(private readonly TikTokOAuthService $oauth) {}

    public function uploadConfigured(): bool
    {
        return in_array(TikTokContentPosting::UPLOAD_SCOPE, $this->scopeList((string) config('services.tiktok.scopes')), true);
    }

    public function uploadPermitted(User $user): bool
    {
        if (! $this->uploadConfigured()) {
            return false;
        }

        $account = $this->account($user);

        return $account !== null
            && $account->is_active
            && in_array(TikTokContentPosting::UPLOAD_SCOPE, $this->scopeList((string) $account->scopes), true);
    }

    public function needsUploadPermission(User $user): bool
    {
        if (! $this->uploadConfigured()) {
            return false;
        }

        $account = ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return false;
        }

        return ! in_array(TikTokContentPosting::UPLOAD_SCOPE, $this->scopeList((string) $account->scopes), true);
    }

    public function dispatchIfReady(TikTokClip $clip): void
    {
        $clip->loadMissing('video', 'user');
        if (! in_array($clip->status, [TikTokCatalog::QUEUED, TikTokCatalog::UPLOAD_FAILED], true)) {
            return;
        }
        if (! $clip->video?->ready || ! $clip->user || ! $this->uploadPermitted($clip->user)) {
            return;
        }

        SendTikTokDraft::dispatch($clip->id);
    }

    public function retry(User $user, TikTokClip $clip): TikTokClip
    {
        if ((int) $clip->user_id !== (int) $user->id) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(TikTokClip::class, [$clip->id]);
        }
        if ($clip->status !== TikTokCatalog::UPLOAD_FAILED) {
            throw new TikTokActionException(
                TikTokCatalog::ERROR_INVALID_STATE,
                'This clip is not waiting to be sent again.',
            );
        }
        $clip->loadMissing('video');
        if (! $clip->video?->ready) {
            throw new TikTokActionException(TikTokCatalog::ERROR_INVALID_STATE, 'Add the video again before sending it.');
        }
        if (! $this->uploadPermitted($user)) {
            $message = $this->uploadConfigured()
                ? 'TikTok needs one more permission. Reconnect to continue.'
                : 'Upload permission: waiting for TikTok approval.';
            throw new TikTokActionException(TikTokCatalog::ERROR_INVALID_STATE, $message);
        }

        $this->dispatchIfReady($clip);

        return $clip->fresh() ?? $clip;
    }

    public function send(int $clipId): void
    {
        $clip = TikTokClip::query()->with(['video', 'user'])->find($clipId);
        if (! $clip || ! $clip->user || ! $clip->video?->ready) {
            return;
        }
        if (! in_array($clip->status, [TikTokCatalog::QUEUED, TikTokCatalog::UPLOADING, TikTokCatalog::UPLOAD_FAILED], true)) {
            return;
        }
        if (! $this->uploadPermitted($clip->user)) {
            return;
        }

        if ($clip->status !== TikTokCatalog::UPLOADING) {
            $clip->transitionTo(TikTokCatalog::UPLOADING);
            $clip->upload_error = null;
            $clip->upload_progress = 5;
            $clip->save();
        }

        try {
            $account = $this->oauth->ensureFresh($this->requireAccount($clip->user));
            if (! $account->is_active) {
                $this->fail($clip, 'TikTok needs you to sign in again.');

                return;
            }

            $needsInit = $clip->bytes_sent_at === null && (
                ! is_string($clip->tiktok_publish_id) || $clip->tiktok_publish_id === ''
                || ! is_string($clip->upload_url) || $clip->upload_url === ''
            );
            if ($needsInit) {
                $init = $this->init($account, $clip);
                if ($init === null) {
                    return;
                }
                $clip->tiktok_publish_id = $init['publish_id'];
                $clip->upload_url = $init['upload_url'];
                $clip->upload_progress = 15;
                $clip->save();
            }

            if ($clip->bytes_sent_at === null) {
                if (! $this->uploadBytes($clip)) {
                    return;
                }
                $clip->bytes_sent_at = now();
                $clip->upload_progress = 80;
                $clip->save();
            }

            $outcome = $this->poll($account, (string) $clip->tiktok_publish_id);
            if ($outcome === 'inbox') {
                $this->succeed($clip);
            } elseif ($outcome === 'failed') {
                $this->fail($clip, "TikTok couldn't take this video. You can try again.");
            }
        } catch (TikTokOAuthException) {
            $this->fail($clip->fresh() ?? $clip, 'TikTok needs you to sign in again.');
        } catch (ConnectionException) {
            $this->fail($clip->fresh() ?? $clip, 'The send stopped. You can try again.');
        }
    }

    public function sweepStuck(): void
    {
        TikTokClip::query()
            ->where('status', TikTokCatalog::UPLOADING)
            ->where('updated_at', '<=', now()->subMinutes(TikTokContentPosting::STUCK_MINUTES))
            ->orderBy('id')
            ->each(function (TikTokClip $clip) {
                $recent = $clip->updated_at !== null && $clip->updated_at->greaterThan(now()->subMinutes(TikTokContentPosting::GIVE_UP_MINUTES));
                $canResume = $recent && is_string($clip->tiktok_publish_id) && $clip->tiktok_publish_id !== '';
                if ($canResume) {
                    $clip->touch();
                    SendTikTokDraft::dispatch($clip->id);

                    return;
                }

                $this->fail($clip, 'The send stopped. You can try again.');
            });
    }

    /**
     * @return array{publish_id: string, upload_url: string}|null
     */
    private function init(ConnectedAccount $account, TikTokClip $clip): ?array
    {
        $size = (int) $clip->video->size;
        $plan = $this->chunkPlan($size);
        $response = $this->http($account)->post(TikTokContentPosting::INBOX_INIT_URL, [
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $size,
                'chunk_size' => $plan['chunk_size'],
                'total_chunk_count' => $plan['total'],
            ],
        ]);

        if ($response->serverError()) {
            $this->fail($clip, "TikTok couldn't start the upload. You can try again.");

            return null;
        }

        $code = (string) $response->json('error.code');
        $publishId = $response->json('data.publish_id');
        $uploadUrl = $response->json('data.upload_url');
        if ($response->failed() || $code !== 'ok' || ! is_string($publishId) || $publishId === '' || ! is_string($uploadUrl) || $uploadUrl === '') {
            $this->fail($clip, $this->plainInitError($code));

            return null;
        }

        return ['publish_id' => $publishId, 'upload_url' => $uploadUrl];
    }

    private function uploadBytes(TikTokClip $clip): bool
    {
        $video = $clip->video;
        $disk = Storage::disk($video->disk ?: TikTokContentPosting::DISK);
        if (! is_string($video->path) || ! $disk->exists($video->path)) {
            $this->fail($clip, 'The video file is missing. Add it again.');

            return false;
        }

        $size = (int) $video->size;
        $plan = $this->chunkPlan($size);
        $absolute = $disk->path($video->path);

        for ($index = 0; $index < $plan['total']; $index++) {
            $start = $index * $plan['chunk_size'];
            $length = $index === $plan['total'] - 1 ? $size - $start : $plan['chunk_size'];
            $end = $start + $length - 1;
            $response = $this->putChunk($absolute, (string) $clip->upload_url, $start, $length, $end, $size);
            if ($response->serverError()) {
                $this->fail($clip, 'The send stopped. You can try again.');

                return false;
            }
            if ($response->failed()) {
                $this->fail($clip, "TikTok couldn't take this video. You can try again.");

                return false;
            }
        }

        return true;
    }

    private function putChunk(string $absolute, string $url, int $start, int $length, int $end, int $size): Response
    {
        $last = null;
        for ($try = 0; $try < 3; $try++) {
            $handle = fopen($absolute, 'rb');
            if ($handle === false) {
                throw new ConnectionException('TikTok video file is missing.');
            }
            $body = new LimitStream(Utils::streamFor($handle), $length, $start);
            try {
                $last = Http::timeout(60)->withHeaders([
                    'Content-Type' => 'video/mp4',
                    'Content-Length' => (string) $length,
                    'Content-Range' => "bytes {$start}-{$end}/{$size}",
                ])->send('PUT', $url, ['body' => $body]);
            } catch (ConnectionException $exception) {
                if ($try === 2) {
                    throw $exception;
                }

                continue;
            }
            if (! $last->serverError()) {
                return $last;
            }
        }

        return $last;
    }

    private function poll(ConnectedAccount $account, string $publishId): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $this->http($account)->post(TikTokContentPosting::STATUS_URL, [
                'publish_id' => $publishId,
            ]);
            if ($response->serverError()) {
                return 'processing';
            }
            if ($response->failed() || (string) $response->json('error.code') !== 'ok') {
                return 'failed';
            }

            $status = (string) $response->json('data.status');
            if ($status === 'SEND_TO_USER_INBOX') {
                return 'inbox';
            }
            if ($status === 'FAILED') {
                return 'failed';
            }
            if ($attempt < 4) {
                usleep(200000);
            }
        }

        return 'processing';
    }

    private function succeed(TikTokClip $clip): void
    {
        $fresh = $clip->fresh() ?? $clip;
        if ($fresh->status === TikTokCatalog::UPLOADING) {
            $fresh->transitionTo(TikTokCatalog::IN_DRAFTS);
        }
        $fresh->upload_error = null;
        $fresh->upload_progress = 100;
        $fresh->upload_url = null;
        $fresh->save();
    }

    private function fail(TikTokClip $clip, string $reason): void
    {
        $fresh = $clip->fresh() ?? $clip;
        if ($fresh->status === TikTokCatalog::UPLOADING) {
            $fresh->transitionTo(TikTokCatalog::UPLOAD_FAILED);
        } elseif (! in_array($fresh->status, [TikTokCatalog::UPLOAD_FAILED, TikTokCatalog::QUEUED], true)) {
            return;
        }
        $fresh->upload_error = $reason;
        $fresh->save();
        Log::warning('TikTok draft upload failed', [
            'clip_id' => $fresh->id,
        ]);
    }

    /**
     * @return array{chunk_size: int, total: int}
     */
    private function chunkPlan(int $size): array
    {
        $max = 64 * 1024 * 1024;
        if ($size <= $max) {
            return ['chunk_size' => max(1, $size), 'total' => 1];
        }

        $chunk = 10 * 1024 * 1024;
        $total = (int) ceil($size / $chunk);

        return ['chunk_size' => $chunk, 'total' => $total];
    }

    private function http(?ConnectedAccount $account): \Illuminate\Http\Client\PendingRequest
    {
        $pending = Http::timeout(60)
            ->retry(3, 100, function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException && $exception->response->serverError();
            }, throw: false);

        if ($account) {
            $pending = $pending->withToken((string) $account->access_token)->acceptJson();
        }

        return $pending;
    }

    private function account(User $user): ?ConnectedAccount
    {
        return ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->first();
    }

    private function requireAccount(User $user): ConnectedAccount
    {
        $account = $this->account($user);
        if (! $account) {
            throw new TikTokOAuthException('failed');
        }

        return $account;
    }

    /**
     * @return list<string>
     */
    private function scopeList(string $scopes): array
    {
        $parts = preg_split('/[\s,]+/', trim($scopes)) ?: [];

        return array_values(array_filter($parts, fn ($part) => $part !== ''));
    }

    private function plainInitError(string $code): string
    {
        return match ($code) {
            'spam_risk_too_many_pending_share', 'spam_risk_user_banned_from_posting' => 'TikTok has too many drafts waiting. Finish one in TikTok, then try again.',
            'access_token_invalid', 'scope_not_authorized', 'scope_permission_missed' => 'TikTok needs one more permission. Reconnect to continue.',
            default => "TikTok couldn't start the upload. You can try again.",
        };
    }
}
