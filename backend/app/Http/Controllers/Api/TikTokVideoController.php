<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TikTokActionException;
use App\Exceptions\TikTokVideoException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TikTokClipResource;
use App\Models\TikTokClip;
use App\Services\TikTok\TikTokDraftService;
use App\Services\TikTok\TikTokEngineService;
use App\Services\TikTok\TikTokVideoService;
use App\Support\TikTokCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TikTokVideoController extends Controller
{
    public function __construct(
        private readonly TikTokVideoService $videos,
        private readonly TikTokDraftService $drafts,
        private readonly TikTokEngineService $engine,
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
        $path = $this->videos->stream($request->user(), $found);
        $name = $found->video?->original_filename ?: 'video.mp4';
        $safe = str_replace(['"', "\r", "\n"], '', $name);

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$safe.'"',
        ]);
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
