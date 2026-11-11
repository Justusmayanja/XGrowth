<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Models\Post;
use App\Models\User;
use App\Services\AICore\AiContextBuilder;
use App\Services\AICore\AICoreService;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\Content\PostVisualService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\TrendEngine\TrendEngineService;
use App\Services\GrowthAgent\SafetyEngineService;
use App\Services\GrowthAgent\ViralityPredictorService;
use App\Services\GrowthAgent\XGoalPlan;
use App\Services\XApi\XApiService;
use App\Support\SetupCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly PostVisualService $visuals,
        private readonly ViralityPredictorService $virality,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        Post::expireUnpostedX(userId: $request->user()->id);

        $validated = $request->validate([
            'mode' => ['nullable', 'in:suggest,describe'],
            'topic' => ['nullable', 'string', 'max:1000'],
            'tone' => ['nullable', 'string', 'max:50'],
            'content_type' => ['nullable', 'in:'.self::CONTENT_TYPES],
            'use_web_search' => ['nullable', 'boolean'],
            'visual_kind' => ['nullable', 'in:none,text,flyer,photo,illustration'],
            'reference' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], [
            'reference.mimes' => 'Use a JPEG, PNG, or WebP photo.',
            'reference.max' => 'That photo is too large. Use one under 5 MB.',
        ]);

        $profile = $request->user()->accountProfile;
        $tone = $validated['tone'] ?? $profile?->primaryTone() ?? 'professional';
        $suggest = ($validated['mode'] ?? 'describe') === 'suggest';
        $typed = trim((string) ($validated['topic'] ?? ''));
        $reference = $this->referenceDataUrl($request);
        if ($request->hasFile('reference') && $reference === null) {
            throw ValidationException::withMessages([
                'reference' => 'Use a JPEG, PNG, or WebP photo.',
            ]);
        }
        if (! $suggest && $typed === '' && $reference === null) {
            throw ValidationException::withMessages([
                'topic' => 'Say what you want to post about.',
            ]);
        }
        $topic = $suggest || $typed === '' ? null : $typed;
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

        $context = $this->suggestContext($request->user(), $profile);

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
                'join_live' => $suggest && $reference === null,
                'reference_image' => $reference,
                'recent_posts' => $context['recent'],
                'suggest' => $suggest ? $context : null,
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
            $result['variants'] = $this->scoreVariants($request->user(), $profile, $result['variants'], $topic);
        }

        return response()->json($this->attachVisual($request, $result, $validated, $contentType, $platform));
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
            'with_visual' => ['nullable', 'boolean'],
            'visual_kind' => ['nullable', 'in:none,text,flyer,photo,illustration'],
            'visual_preview' => ['nullable', 'regex:/^[a-f0-9]{32}$/'],
        ]);

        $contentType = $validated['content_type'] ?? 'tweet';
        $mediaPath = null;
        $previewId = $validated['visual_preview'] ?? null;
        $preview = is_string($previewId) && $previewId !== ''
            ? $this->visuals->readPreview($request->user(), $previewId)
            : null;
        if ($preview !== null) {
            $mediaPath = $preview['path'];
        }
        $visualKind = $this->requestedVisualKind($validated);
        if ($mediaPath === null && $visualKind !== null && in_array($contentType, ['tweet', 'quote'], true)) {
            try {
                $made = $this->visuals->make(
                    $request->user(),
                    $request->user()->accountProfile,
                    $validated['content'],
                    $visualKind === 'auto' ? null : $visualKind,
                );
                $mediaPath = $made['path'] ?? null;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $predicted = $validated['virality_predicted'] ?? null;
        if (SetupCatalog::platformForContentType($contentType) === 'x') {
            try {
                $predicted = $this->scoreDraft($request->user(), $validated['content'])['score'];
            } catch (Throwable $e) {
                report($e);
            }
        }

        $attributes = [
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'content_type' => $contentType,
            'status' => 'draft',
            'virality_predicted' => $predicted,
        ];
        if (Schema::hasColumn('posts', 'media_path')) {
            $attributes['media_path'] = $mediaPath;
        }

        $post = Post::create($attributes);
        if ($preview !== null && is_string($previewId) && $previewId !== '') {
            $this->visuals->forgetPreview($request->user(), $previewId);
        }

        return response()->json([
            'post' => $post,
            'media_attached' => $mediaPath !== null,
        ], 201);
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

        $accountId = (int) $account->id;
        if (! $safety->canAct($request->user(), 'post', $accountId)) {
            return response()->json(['message' => $safety->postHoldReason($request->user(), $accountId, false) ?: 'The daily post limit is reached.'], 429);
        }

        if (app(\App\Services\ApiSpend\ApiSpendService::class)->containsLink($post->content)) {
            return response()->json(['message' => 'Links stay out of this app. Remove the link and post the words.'], 422);
        }

        try {
            $platformPostId = $x->publishText($account, $post->content, $post->content_type, $post->media_path);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $post->update([
            'status' => 'posted',
            'platform_post_id' => $platformPostId,
            'connected_account_id' => $account->id,
            'posted_at' => now(),
        ]);
        $safety->recordAction($request->user(), 'post', $accountId);

        $payload = ['post' => $post->fresh()];
        if ($post->media_path && $x->mediaAttached === false) {
            $payload['media_warning'] = 'Posted the words. Reconnect X so the picture can go with them.';
        }

        return response()->json($payload);
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

        $contentType = $validated['content_type'] ?? $post->content_type;
        $predicted = $validated['virality_predicted'] ?? $post->virality_predicted;
        if (SetupCatalog::platformForContentType($contentType) === 'x') {
            try {
                $predicted = $this->scoreDraft($request->user(), $validated['content'])['score'];
            } catch (Throwable $e) {
                report($e);
            }
        }

        $post->update([
            'content' => $validated['content'],
            'content_type' => $contentType,
            'virality_predicted' => $predicted,
        ]);

        return response()->json(['post' => $post->fresh()]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,scheduled,posted,failed'],
        ]);

        Post::expireUnpostedX(userId: $request->user()->id);

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

        if (is_string($post->media_path) && $post->media_path !== '') {
            Storage::disk('local')->delete($post->media_path);
        }

        $post->delete();

        return response()->json(['message' => 'Draft deleted']);
    }

    public function media(Request $request, Post $post): Response
    {
        if ($post->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! is_string($post->media_path) || $post->media_path === '' || ! Storage::disk('local')->exists($post->media_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($post->media_path);
    }

    public function visualPreview(Request $request, string $preview): Response
    {
        $held = $this->visuals->readPreview($request->user(), $preview);
        if ($held === null) {
            abort(404);
        }

        return Storage::disk('local')->response($held['path']);
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function scoreVariants(User $user, AccountProfile $profile, array $variants, ?string $topic): array
    {
        $moment = $this->bestMoment($user, $topic);
        $floor = (int) ($profile->automation_level ?? 1) >= 3 ? 60 : 75;
        $accountId = $user->connectedAccounts()->where('platform', 'x')->where('is_active', true)->orderBy('id')->value('id');

        $items = [];
        foreach ($variants as $variant) {
            $prediction = $this->virality->predict(
                $user,
                (string) ($variant['content'] ?? ''),
                $moment['trend'],
                $profile,
                $accountId ? (int) $accountId : null,
                $floor,
                $moment['score'],
            );
            $items[] = array_merge($variant, $prediction);
        }

        $ranked = $this->virality->pickOne($items, $floor);

        foreach ($ranked as $index => $item) {
            $ranked[$index]['virality_score'] = (int) round((float) $item['score']);
            $ranked[$index]['estimated_reach'] = $item['estimated_reach'];
            $ranked[$index]['worth_posting'] = $item['worth_posting'];
            $ranked[$index]['virality_reason'] = $item['reason'];
            $ranked[$index]['pick'] = $item['pick'];
            unset($ranked[$index]['score'], $ranked[$index]['parts'], $ranked[$index]['boost']);
        }

        return $ranked;
    }

    /**
     * @return array{score: float, worth_posting: bool, reason: string, estimated_reach: string}
     */
    private function scoreDraft(User $user, string $content): array
    {
        $profile = $user->accountProfile;
        $moment = $this->bestMoment($user, $content);
        $floor = (int) ($profile?->automation_level ?? 1) >= 3 ? 60 : 75;
        $accountId = $user->connectedAccounts()->where('platform', 'x')->where('is_active', true)->orderBy('id')->value('id');

        return $this->virality->predict(
            $user,
            $content,
            $moment['trend'],
            $profile,
            $accountId ? (int) $accountId : null,
            $floor,
            $moment['score'],
        );
    }

    /**
     * @return array{trend: \App\Models\Trend|null, score: float|null}
     */
    private function bestMoment(User $user, ?string $topic): array
    {
        $fromAccount = $this->trends->rankedFromAccount($user);
        if (is_array($fromAccount) && $fromAccount !== []) {
            return [
                'trend' => $fromAccount[0]['trend'],
                'score' => (float) $fromAccount[0]['opportunity_score'],
            ];
        }

        $profile = $user->accountProfile;
        if ($profile) {
            $ranked = $this->opportunityScorer->rankedForProfile($profile, 5);
            if ($ranked !== []) {
                return [
                    'trend' => $ranked[0]['trend'],
                    'score' => (float) $ranked[0]['opportunity_score'],
                ];
            }
        }

        return ['trend' => null, 'score' => $topic ? 48.0 : 35.0];
    }

    /**
     * @param  array{visual_kind?: string|null, with_visual?: mixed}  $validated
     * @return 'flyer'|'photo'|'illustration'|'auto'|null
     */
    private function requestedVisualKind(array $validated): ?string
    {
        $kind = $validated['visual_kind'] ?? null;
        if (in_array($kind, ['flyer', 'photo', 'illustration'], true)) {
            return $kind;
        }
        if (in_array($kind, ['none', 'text'], true)) {
            return null;
        }

        return ! empty($validated['with_visual']) ? 'auto' : null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attachVisual(Request $request, array $result, array $validated, string $contentType, string $platform): array
    {
        $kind = $this->requestedVisualKind([
            'visual_kind' => is_string($validated['visual_kind'] ?? null) ? $validated['visual_kind'] : null,
        ]);
        if ($kind === null || $kind === 'auto' || $platform !== 'x' || ! in_array($contentType, ['tweet', 'quote'], true)) {
            return $result;
        }

        $caption = trim((string) ($result['variants'][0]['content'] ?? ''));
        if ($caption === '') {
            return $result;
        }

        $refusal = app(ApiSpendService::class)->refusal($request->user()->id, ApiSpendService::IMAGE_MILLS);
        if ($refusal !== null) {
            $result['visual_error'] = $refusal;

            return $result;
        }

        set_time_limit(120);

        try {
            $preview = $this->visuals->preview(
                $request->user(),
                $request->user()->accountProfile,
                $caption,
                $kind,
            );
        } catch (Throwable $e) {
            report($e);
            $preview = null;
        }

        if ($preview === null) {
            $result['visual_error'] = 'A picture could not be made.';

            return $result;
        }

        $result['visual'] = [
            'id' => $preview['id'],
            'kind' => $preview['kind'],
        ];

        return $result;
    }

    private function referenceDataUrl(Request $request): ?string
    {
        $file = $request->file('reference');
        if ($file === null || ! $file->isValid()) {
            return null;
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            return null;
        }

        $info = @getimagesize($path);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
