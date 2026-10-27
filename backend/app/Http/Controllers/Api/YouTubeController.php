<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\YouTubeVideo;
use App\Services\YouTube\YouTubeEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YouTubeController extends Controller
{
    public function __construct(private readonly YouTubeEngineService $engine) {}

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
}
