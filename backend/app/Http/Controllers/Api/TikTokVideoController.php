<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TikTokActionException;
use App\Exceptions\TikTokVideoException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TikTokClipResource;
use App\Models\TikTokClip;
use App\Services\Storage\MediaStore;
use App\Services\TikTok\TikTokDraftService;
use App\Services\TikTok\TikTokEngineService;
use App\Services\TikTok\TikTokRenderService;
use App\Services\TikTok\TikTokVideoService;
use App\Support\TikTokCatalog;
use App\Support\TikTokVideoCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TikTokVideoController extends Controller
{
    public function __construct(
        private readonly TikTokVideoService $videos,
        private readonly TikTokDraftService $drafts,
        private readonly TikTokEngineService $engine,
        private readonly TikTokRenderService $renders,
        private readonly MediaStore $media,
    ) {}

    public function start(Request $request, int $clip): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:180'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $started = $this->videos->start(
                $request->user(),
                $this->clip($request, $clip),
                $data['filename'],
                (int) $data['size'],
            );
        } catch (TikTokVideoException|TikTokActionException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => $started]);
    }

    public function chunk(Request $request, int $clip, int $index): JsonResponse
    {
        try {
            $received = $this->videos->storeChunk(
                $request->user(),
                $this->clip($request, $clip),
                $index,
                $request->getContent(),
            );
        } catch (TikTokVideoException|TikTokActionException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => ['received' => $received]]);
    }

    public function complete(Request $request, int $clip): JsonResponse
    {
        try {
            $saved = $this->videos->complete($request->user(), $this->clip($request, $clip));
        } catch (TikTokVideoException|TikTokActionException $e) {
            return $this->error($e);
        }

        return TikTokClipResource::make($saved->load('video'))->response();
    }

    public function show(Request $request, int $clip): BinaryFileResponse
    {
        $found = $this->clip($request, $clip);
        $local = $this->videos->stream($request->user(), $found);
        $name = $found->video?->original_filename ?: 'video.mp4';
        $safe = str_replace(['"', "\r", "\n"], '', $name);

        $response = response()->file($local->path, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$safe.'"',
        ]);
        if ($local->temporary) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }

    public function retry(Request $request, int $clip): JsonResponse
    {
        try {
            $saved = $this->drafts->retry($request->user(), $this->clip($request, $clip));
        } catch (TikTokActionException $e) {
            return $this->error($e);
        }

        return TikTokClipResource::make($saved->load('video'))->response();
    }

    public function renderStatus(Request $request, int $clip): JsonResponse
    {
        $found = $this->clip($request, $clip);
        $render = $found->package['render'] ?? [];

        return response()->json(['data' => $this->renderPayload($found, $render)]);
    }

    public function regenerate(Request $request, int $clip): JsonResponse
    {
        $found = $this->clip($request, $clip);
        $render = $found->package['render'] ?? [];
        if (! in_array($found->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)) {
            return $this->error(new \App\Exceptions\TikTokActionException(TikTokCatalog::ERROR_INVALID_STATE, 'This clip can no longer be made again.'));
        }
        if (! in_array($render['status'] ?? '', [TikTokCatalog::RENDER_FAILED, TikTokCatalog::RENDER_READY], true)) {
            return $this->error(new \App\Exceptions\TikTokActionException(TikTokCatalog::ERROR_INVALID_STATE, 'This clip can no longer be made again.'));
        }
        if ((int) ($render['regenerations'] ?? 0) >= TikTokVideoCatalog::MAX_REGENERATIONS) {
            return response()->json([
                'error' => [
                    'code' => TikTokVideoCatalog::ERROR_REGENERATE_LIMIT,
                    'message' => 'This clip has already been made again twice.',
                ],
            ], 429);
        }
        $video = $found->video;
        if ($video && $video->source === 'generated') {
            $video->delete();
        }
        $this->renders->dispatch($found->fresh() ?? $found, true);

        return response()->json(['data' => $this->renderPayload($found->fresh() ?? $found, ($found->fresh() ?? $found)->package['render'] ?? [])]);
    }

    public function preview(Request $request, int $clip): JsonResponse
    {
        $found = $this->clip($request, $clip)->load('video');
        $video = $found->video;
        if (! $video || ! $video->ready || ! is_string($video->preview_path) || $video->preview_path === '') {
            abort(404);
        }
        $expires = now()->addMinutes(TikTokVideoCatalog::SIGNED_URL_MINUTES);
        $signed = ['clip' => $found->id, 'owner' => $found->user_id];
        if ($this->media->supportsTemporaryUrls()) {
            $preview = $this->media->temporaryUrl($video->preview_path, $expires);
            $poster = is_string($video->poster_path) && $video->poster_path !== ''
                ? $this->media->temporaryUrl($video->poster_path, $expires)
                : null;
        } else {
            $preview = URL::temporarySignedRoute('tiktok.video.preview-file', $expires, $signed);
            $poster = is_string($video->poster_path) && $video->poster_path !== ''
                ? URL::temporarySignedRoute('tiktok.video.poster', $expires, $signed)
                : null;
        }

        return response()->json(['data' => [
            'preview_url' => $preview,
            'poster_url' => $poster,
            'expires_at' => $expires->utc()->toIso8601String(),
            'preview_bytes' => $video->preview_bytes,
            'duration_seconds' => $video->duration_seconds,
        ]]);
    }

    public function previewFile(Request $request, int $clip): BinaryFileResponse
    {
        return $this->streamOwned($request, $clip, 'preview_path', 'video/mp4');
    }

    public function poster(Request $request, int $clip): BinaryFileResponse
    {
        return $this->streamOwned($request, $clip, 'poster_path', 'image/jpeg');
    }

    private function streamOwned(Request $request, int $clip, string $column, string $type): BinaryFileResponse
    {
        $owner = (int) $request->query('owner');
        $found = TikTokClip::query()->whereKey($clip)->where('user_id', $owner)->firstOrFail()->load('video');
        $video = $found->video;
        $key = $video?->{$column} ?? null;
        if (! $video || ! $video->ready || ! is_string($key) || $key === '') {
            abort(404);
        }
        $local = $this->media->materialize($video->disk ?: \App\Support\TikTokContentPosting::DISK, $key);
        $response = response()->file($local->path, ['Content-Type' => $type, 'Accept-Ranges' => 'bytes']);
        if ($local->temporary) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $render
     * @return array<string, mixed>
     */
    private function renderPayload(TikTokClip $clip, array $render): array
    {
        $status = (string) ($render['status'] ?? TikTokCatalog::RENDER_NONE);
        $regenerations = (int) ($render['regenerations'] ?? 0);

        return [
            'status' => $status,
            'label' => TikTokCatalog::renderLabel($status),
            'note' => TikTokCatalog::renderNote($render),
            'ready' => (bool) ($render['ready'] ?? false),
            'can_regenerate' => in_array($clip->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)
                && in_array($status, [TikTokCatalog::RENDER_FAILED, TikTokCatalog::RENDER_READY], true)
                && $regenerations < TikTokVideoCatalog::MAX_REGENERATIONS,
        ];
    }

    public function posted(Request $request, int $clip): JsonResponse
    {
        try {
            $saved = $this->engine->markPosted($request->user(), $this->clip($request, $clip));
        } catch (TikTokActionException $e) {
            return $this->error($e);
        }

        return TikTokClipResource::make($saved->load('video'))->response();
    }

    private function clip(Request $request, int $clip): TikTokClip
    {
        return TikTokClip::query()->forUser($request->user())->whereKey($clip)->firstOrFail();
    }

    private function error(TikTokVideoException|TikTokActionException $e): JsonResponse
    {
        $code = $e instanceof TikTokActionException ? $e->errorCode : $e->errorCode;
        $status = $code === TikTokCatalog::ERROR_INVALID_STATE ? 409 : 422;

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $e->getMessage(),
            ],
        ], $status);
    }
}
