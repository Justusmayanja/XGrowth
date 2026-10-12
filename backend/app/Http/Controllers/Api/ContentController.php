<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Models\Post;
use App\Models\Trend;
use App\Models\User;
use App\Services\AICore\AiContextBuilder;
use App\Services\AICore\AICoreService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\TrendEngine\TrendEngineService;
use App\Support\SetupCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ContentController extends Controller
{
    private const CONTENT_TYPES = 'tweet,thread,quote,poll,tiktok_caption,youtube_title,youtube_description,youtube_community';

    public function __construct(
        private readonly AICoreService $aiCore,
        private readonly AiContextBuilder $contextBuilder,
        private readonly OpportunityScorerService $opportunityScorer,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'in:suggest,describe'],
            'topic' => ['required_unless:mode,suggest', 'nullable', 'string', 'max:1000'],
            'tone' => ['nullable', 'string', 'max:50'],
            'content_type' => ['nullable', 'in:'.self::CONTENT_TYPES],
            'use_web_search' => ['nullable', 'boolean'],
        ]);

        $profile = $request->user()->accountProfile;
        $tone = $validated['tone'] ?? $profile?->primaryTone() ?? 'professional';
        $suggest = ($validated['mode'] ?? 'describe') === 'suggest';
        $topic = $suggest ? null : trim((string) $validated['topic']);
        $niche = $profile?->resolvedNiche() ?? 'your niche';
        $contentType = $validated['content_type'] ?? 'tweet';
        $platform = SetupCatalog::platformForContentType($contentType);
        $goal = $profile?->goalSentenceFor($platform) ?? 'grow an audience on '.SetupCatalog::PLATFORM_NAMES[$platform];

        try {
            $result = $this->aiCore->generateContentVariants([
                'user' => $request->user(),
                'topic' => $topic,
                'tone' => $tone,
                'niche' => $niche,
                'goal' => $goal,
                'purpose' => $profile?->purposeSentence(),
                'content_type' => $contentType,
                'audience' => $profile?->audience,
                'description' => $profile?->description,
                'use_web_search' => (bool) ($validated['use_web_search'] ?? false),
                'suggest' => $suggest ? $this->suggestContext($request->user(), $profile) : null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                str_contains(strtolower($e->getMessage()), 'limit reached') ? 429 : 503
            );
        }

        return response()->json($result);
    }

    /**
     * @return array{trends: list<string>, recent: list<string>, prefer: string, evergreen: list<string>}
     */
    private function suggestContext(User $user, ?AccountProfile $profile): array
    {
        $trends = [];

        if ($profile?->onboarding_completed) {
            if (Trend::count() === 0) {
                app(TrendEngineService::class)->seedMockTrends();
            }

            foreach ($this->opportunityScorer->rankedForProfile($profile, 3) as $item) {
                $trends[] = mb_substr($item['trend']->name, 0, 80);
            }
        }

        $recent = $user->posts()
            ->latest()
            ->limit(6)
            ->pluck('content')
            ->map(fn (string $content) => mb_substr($content, 0, 140))
            ->all();

        return [
            'trends' => $trends,
            'recent' => $recent,
            'prefer' => $this->contextBuilder->contentMixDigest($user)['prefer'],
            'evergreen' => $profile ? $this->contextBuilder->originalIdeasDigest($profile) : [],
        ];
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'content_type' => ['nullable', 'in:'.self::CONTENT_TYPES],
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
            'content_type' => ['nullable', 'in:'.self::CONTENT_TYPES],
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
