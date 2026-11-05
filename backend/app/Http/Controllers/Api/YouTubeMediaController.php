<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\YouTubeVideo;
use App\Services\Storage\MediaStore;
use App\Services\YouTube\YouTubeEngineService;
use App\Services\YouTube\YouTubeRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class YouTubeMediaController extends Controller
{
    public function __construct(
        private readonly YouTubeRenderService $renders,
        private readonly YouTubeEngineService $engine,
        private readonly MediaStore $media,
    ) {}

    public function render(Request $request, YouTubeVideo $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);
        set_time_limit(180);
        $this->renders->render($video);

        return response()->json($this->engine->desk($request->user()));
    }

    public function show(Request $request, YouTubeVideo $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);
        $render = $this->readyRender($video);
        $expires = now()->addMinutes(30);
        $signed = ['video' => $video->id, 'owner' => $video->user_id];
        if ($this->media->supportsTemporaryUrls(MediaStore::YOUTUBE)) {
            $preview = $this->media->temporaryUrl((string) $render['preview_path'], $expires, MediaStore::YOUTUBE);
            $poster = $this->media->temporaryUrl((string) $render['poster_path'], $expires, MediaStore::YOUTUBE);
            $download = $this->media->temporaryUrl((string) $render['path'], $expires, MediaStore::YOUTUBE);
        } else {
            $preview = URL::temporarySignedRoute('youtube.media.preview', $expires, $signed);
            $poster = URL::temporarySignedRoute('youtube.media.poster', $expires, $signed);
            $download = URL::temporarySignedRoute('youtube.media.download', $expires, $signed);
        }

        return response()->json(['data' => [
            'preview_url' => $preview,
            'poster_url' => $poster,
            'download_url' => $download,
            'expires_at' => $expires->utc()->toIso8601String(),
            'bytes' => $render['bytes'] ?? null,
            'duration_seconds' => $render['duration_seconds'] ?? null,
            'note' => 'Local preview. Nothing has been uploaded.',
        ]]);
    }

    public function preview(Request $request, int $video): BinaryFileResponse
    {
        return $this->stream($request, $video, 'preview_path', 'video/mp4', false);
    }

    public function poster(Request $request, int $video): BinaryFileResponse
    {
        return $this->stream($request, $video, 'poster_path', 'image/jpeg', false);
    }

    public function download(Request $request, int $video): BinaryFileResponse
    {
        return $this->stream($request, $video, 'path', 'video/mp4', true);
    }

    private function stream(Request $request, int $video, string $field, string $type, bool $download): BinaryFileResponse
    {
        $owner = (int) $request->query('owner');
        $found = YouTubeVideo::query()->whereKey($video)->where('user_id', $owner)->firstOrFail();
        $render = $this->readyRender($found);
        $key = $render[$field] ?? null;
        if (! is_string($key) || $key === '') {
            abort(404);
        }
        $local = $this->media->materialize(YouTubeRenderService::DISK, $key);
        $headers = ['Content-Type' => $type, 'Accept-Ranges' => 'bytes'];
        $response = $download
            ? response()->download($local->path, 'youtube-'.$found->id.'.mp4', $headers)
            : response()->file($local->path, $headers);
        if ($local->temporary) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function readyRender(YouTubeVideo $video): array
    {
        $render = is_array($video->package) ? ($video->package['render'] ?? null) : null;
        if (! is_array($render) || ($render['ready'] ?? false) !== true || ! is_string($render['path'] ?? null) || $render['path'] === '') {
            abort(404);
        }

        return $render;
    }
}
