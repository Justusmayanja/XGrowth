<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Models\Post;
use App\Models\User;
use App\Services\AICore\AiContextBuilder;
use App\Services\AICore\AICoreService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\TrendEngine\TrendEngineService;
use App\Services\GrowthAgent\SafetyEngineService;
use App\Services\GrowthAgent\XGoalPlan;
use App\Services\XApi\XApiService;
use App\Support\SetupCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ContentController extends Controller
{
    private const CONTENT_TYPES = 'tweet,thread,quote,poll,tiktok_caption,youtube_title,youtube_description,youtube_community';

    public function __construct(
        private readonly AICoreService $aiCore,
        private readonly AiContextBuilder $contextBuilder,
        private readonly OpportunityScorerService $opportunityScorer,
        private readonly TrendEngineService $trends,
        private readonly XGoalPlan $goals,
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
        $search = (bool) ($validated['use_web_search'] ?? false);

        if ($suggest || $search) {
            $fromAccount = $this->trends->rankedFromAccount($request->user());
            if (! is_array($fromAccount) || $fromAccount === []) {
                try {
                    $this->trends->ensureCurrentTrends($request->user());
                } catch (Throwable) {
                    // A draft can still be written from the account's point of view.
                }
            }
        }

        try {
            $result = $this->aiCore->generateContentVariants([
                'user' => $request->user(),
                'topic' => $topic,
                'tone' => $tone,
                'niche' => $niche,
                'goal' => $goal,
                'goal_instruction' => $platform === 'x' && $profile ? $this->goals->postBrief($profile) : null,
                'goal_lesson' => $platform === 'x' && $profile ? $this->goals->results($profile)['lesson'] : null,
                'purpose' => $profile?->purposeSentence(),
                'content_type' => $contentType,
                'audience' => $profile?->audience,
                'description' => $profile?->description,
                'use_web_search' => $search,
                'join_live' => $suggest,
                'suggest' => $suggest ? $this->suggestContext($request->user(), $profile) : null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                str_contains(strtolower($e->getMessage()), 'limit reached') ? 429 : 503
            );
        }

        if ($platform === 'x' && $profile) {
            foreach ($result['variants'] as $index => $variant) {
                $result['variants'][$index]['content'] = $this->goals->finishText($profile, (string) ($variant['content'] ?? ''));
            }
        }

        return response()->json($result);
    }

    /**
     * @return array{trends: list<string>, recent: list<string>, prefer: string, evergreen: list<string>}
     */
    private function suggestContext(User $user, ?AccountProfile $profile): array
    {
        $trends = [];
        $fromNiche = $this->trends->rankedInNiche($user);

        if (is_array($fromNiche) && $fromNiche !== []) {
            foreach (array_slice($fromNiche, 0, 3) as $item) {
                $trends[] = mb_substr($item['trend']->name, 0, 80);
            }
        }

        $account = $user->connectedAccounts()->where('platform', 'x')->where('is_active', true)->first();
        $spoken = $account ? app(\App\Services\XApi\XApiService::class)->voiceSamples($account) : [];
        $recent = array_values(array_unique(array_merge(
            $spoken,
            $user->posts()
                ->latest()
                ->limit(6)
                ->pluck('content')
                ->map(fn (string $content) => mb_substr($content, 0, 140))
                ->all(),
        )));

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

    public function publish(Request $request, Post $post, XApiService $x, SafetyEngineService $safety): JsonResponse
    {
        if ($post->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($post->status !== 'draft') {
            return response()->json(['message' => 'Only a draft can be posted.'], 422);
        }

        if (SetupCatalog::platformForContentType($post->content_type) !== 'x') {
            return response()->json(['message' => 'Only X drafts can be posted from here.'], 422);
        }

        $account = $request->user()->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json(['message' => 'Connect X before posting.'], 422);
        }

        if (! $safety->canAct($request->user(), 'post')) {
            return response()->json(['message' => 'The daily post limit is reached.'], 429);
        }

        if (app(\App\Services\ApiSpend\ApiSpendService::class)->containsLink($post->content)) {
            return response()->json(['message' => 'Links stay out of this app. Remove the link and post the words.'], 422);
        }

        try {
            $platformPostId = $x->publishText($account, $post->content, $post->content_type);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $post->update([
            'status' => 'posted',
            'platform_post_id' => $platformPostId,
            'connected_account_id' => $account->id,
            'posted_at' => now(),
        ]);
        $safety->recordAction($request->user(), 'post');

        return response()->json(['post' => $post->fresh()]);
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

        $profile = $request->user()->accountProfile;
        $purpose = trim((string) ($profile?->description ?? '').' '.(string) ($profile?->audience ?? '').' '.(string) ($profile?->resolvedNiche() ?? ''));
        $x = app(XApiService::class);
        $posts->setCollection(
            $posts->getCollection()
                ->reject(function ($post) use ($x, $purpose) {
                    if ($post->status !== 'draft') {
                        return false;
                    }
                    if (str_starts_with((string) $post->content_type, 'tiktok') || str_starts_with((string) $post->content_type, 'youtube')) {
                        return false;
                    }

                    return $x->isOutsideTheAccount((string) $post->content, $purpose);
                })
                ->values()
        );

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
