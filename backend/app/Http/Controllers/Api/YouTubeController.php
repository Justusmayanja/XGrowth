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
