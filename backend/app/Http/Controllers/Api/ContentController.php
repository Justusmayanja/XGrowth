<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\AICore\AICoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ContentController extends Controller
{
    public function __construct(
        private readonly AICoreService $aiCore,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'tone' => ['nullable', 'string', 'max:50'],
            'content_type' => ['nullable', 'in:tweet,thread,quote,poll'],
            'use_web_search' => ['nullable', 'boolean'],
        ]);

        $profile = $request->user()->accountProfile;
        $tone = $validated['tone'] ?? $profile?->primaryTone() ?? 'professional';
        $topic = $validated['topic'];
        $niche = $profile?->resolvedNiche() ?? 'your niche';
        $goal = str_replace('_', ' ', $profile?->primaryGoal() ?? 'grow on X');

        try {
            $result = $this->aiCore->generateContentVariants([
                'user' => $request->user(),
                'topic' => $topic,
                'tone' => $tone,
                'niche' => $niche,
                'goal' => $goal,
                'content_type' => $validated['content_type'] ?? 'tweet',
                'audience' => $profile?->audience,
                'description' => $profile?->description,
                'use_web_search' => (bool) ($validated['use_web_search'] ?? false),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                str_contains(strtolower($e->getMessage()), 'limit reached') ? 429 : 503
            );
        }

        return response()->json($result);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'content_type' => ['nullable', 'in:tweet,thread,quote,poll'],
            'virality_predicted' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $post = Post::create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'content_type' => $validated['content_type'] ?? 'tweet',
            'status' => 'draft',
            'virality_predicted' => $validated['virality_predicted'] ?? null,
        ]);

        return response()->json(['post' => $post], 201);
    }

    public function updateDraft(Request $request, Post $post): JsonResponse
    {
        if ($post->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($post->status !== 'draft') {
            return response()->json(['message' => 'Only drafts can be edited.'], 422);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'content_type' => ['nullable', 'in:tweet,thread,quote,poll'],
            'virality_predicted' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $post->update([
            'content' => $validated['content'],
            'content_type' => $validated['content_type'] ?? $post->content_type,
            'virality_predicted' => $validated['virality_predicted'] ?? $post->virality_predicted,
        ]);

        return response()->json(['post' => $post->fresh()]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,scheduled,posted,failed'],
        ]);

        $posts = $request->user()
            ->posts()
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status'])
            )
            ->latest()
            ->paginate(20);

        return response()->json($posts);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        if ($post->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($post->status !== 'draft') {
            return response()->json(['message' => 'Only drafts can be deleted.'], 422);
        }

        $post->delete();

        return response()->json(['message' => 'Draft deleted']);
    }
}
