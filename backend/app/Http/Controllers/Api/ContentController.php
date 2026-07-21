<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'tone' => ['nullable', 'string', 'max:50'],
            'content_type' => ['nullable', 'in:tweet,thread,quote,poll'],
        ]);

        $profile = $request->user()->accountProfile;
        $tone = $validated['tone'] ?? $profile?->primaryTone() ?? 'professional';
        $topic = $validated['topic'];
        $niche = $profile?->resolvedNiche() ?? 'your niche';
        $goal = str_replace('_', ' ', $profile?->primaryGoal() ?? 'grow on X');

        $variants = [
            [
                'content' => "On {$topic}: this is exactly the kind of moment {$niche} accounts should be talking about. Here's my {$tone} read — the conversation is missing the real story.",
                'virality_score' => 82,
                'estimated_reach' => 'high',
            ],
            [
                'content' => "Honest question for {$niche} people: what's your take on {$topic}? Trying to {$goal} and want to hear what the timeline actually thinks.",
                'virality_score' => 86,
                'estimated_reach' => 'high',
            ],
            [
                'content' => "Hot {$tone} take on {$topic}. If you're building in {$niche}, this matters more than people realize. Agree or push back — I'll reply.",
                'virality_score' => 74,
                'estimated_reach' => 'medium',
            ],
        ];

        return response()->json([
            'variants' => $variants,
            'note' => "Drafts tailored to {$niche} · {$tone} tone",
        ]);
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
