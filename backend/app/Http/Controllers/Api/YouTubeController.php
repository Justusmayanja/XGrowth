<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\YouTubeVideo;
use App\Services\YouTube\YouTubeEngineService;
use App\Services\YouTube\YouTubeResearchRefreshService;
use App\Services\YouTube\YouTubeUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YouTubeController extends Controller
{
    public function __construct(
        private readonly YouTubeEngineService $engine,
        private readonly YouTubeResearchRefreshService $refresh,
        private readonly YouTubeUploadService $uploads,
    ) {}

    public function run(Request $request): JsonResponse
    {
        $this->engine->run($request->user());

        return response()->json($this->engine->desk($request->user()));
    }

    public function confirm(Request $request, YouTubeVideo $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);
        $this->engine->confirm($video);

        return response()->json($this->engine->desk($request->user()));
    }

    public function results(Request $request, YouTubeVideo $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'views' => ['required', 'integer', 'min:0'],
            'average_view_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->engine->recordResults(
            $video,
            (int) $validated['views'],
            (float) $validated['average_view_percent'],
        );

        return response()->json($this->engine->desk($request->user()));
    }

    public function listings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'videos' => ['required', 'array', 'min:1', 'max:200'],
            'videos.*.channel' => ['required', 'string', 'max:120'],
            'videos.*.title' => ['required', 'string', 'max:200'],
            'videos.*.views' => ['required', 'integer', 'min:0'],
            'videos.*.url' => ['nullable', 'string', 'max:300'],
            'videos.*.duration' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->engine->storeListings($request->user(), $validated['videos']);

        return response()->json($this->engine->desk($request->user()));
    }

    public function refreshChannelVideos(Request $request): JsonResponse
    {
        $this->refresh->channelVideos($request->user());

        return response()->json($this->engine->desk($request->user()));
    }

    public function refreshNicheSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $this->refresh->nicheSearch($request->user(), $validated['query']);

        return response()->json($this->engine->desk($request->user()));
    }

    public function refreshComments(Request $request, YouTubeVideo $video): JsonResponse
    {
        $this->owned($request, $video);
        $this->refresh->comments($request->user(), $video, $this->youtubeVideoId($request));

        return response()->json($this->engine->desk($request->user()));
    }

    public function refreshViews(Request $request, YouTubeVideo $video): JsonResponse
    {
        $this->owned($request, $video);
        $this->refresh->views($request->user(), $video, $this->youtubeVideoId($request));

        return response()->json($this->engine->desk($request->user()));
    }

    public function refreshRetention(Request $request, YouTubeVideo $video): JsonResponse
    {
        $this->owned($request, $video);
        $this->refresh->retention($request->user(), $video, $this->youtubeVideoId($request));

        return response()->json($this->engine->desk($request->user()));
    }

    public function transcript(Request $request, YouTubeVideo $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'transcript' => ['required', 'string', 'max:200000'],
        ]);
        $this->engine->storeTranscript($video, $validated['transcript']);

        return response()->json($this->engine->desk($request->user()));
    }

    public function retention(Request $request, YouTubeVideo $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'csv' => ['required', 'string', 'max:200000'],
            'duration' => ['nullable', 'numeric', 'min:1'],
        ]);
        $this->engine->recordRetention(
            $video,
            $validated['csv'],
            isset($validated['duration']) ? (float) $validated['duration'] : null,
        );

        return response()->json($this->engine->desk($request->user()));
    }

    public function comments(Request $request, YouTubeVideo $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'comments' => ['required', 'string', 'max:20000'],
        ]);
        $this->engine->storeComments($request->user(), $video, $validated['comments']);

        return response()->json($this->engine->desk($request->user()));
    }

    public function upload(Request $request, YouTubeVideo $video): JsonResponse
    {
        $this->owned($request, $video);
        $privacy = $request->validate([
            'privacy' => ['sometimes', 'nullable', 'in:public,unlisted,private'],
        ])['privacy'] ?? null;
        $this->uploads->attempt($request->user(), $video, is_string($privacy) ? $privacy : null);

        return response()->json($this->engine->desk($request->user()));
    }

    public function footage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'footage' => ['required', 'file', 'mimes:mp4,mov,webm', 'max:102400'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $request->user()->loadMissing('accountProfile');
        $this->engine->storeFootage($request->user(), $validated['footage'], $validated['note'] ?? null);

        return response()->json($this->engine->desk($request->user()));
    }

    private function youtubeVideoId(Request $request): string
    {
        return $request->validate([
            'youtube_video_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{6,20}$/'],
        ])['youtube_video_id'];
    }

    private function owned(Request $request, YouTubeVideo $video): void
    {
        abort_unless($video->user_id === $request->user()->id, 404);
    }
}
