<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetup;
use App\Models\Trend;
use App\Services\AICore\DeskMomentService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\XApi\XApiService;
use Throwable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly OpportunityScorerService $opportunityScorer,
        private readonly XApiService $x,
        private readonly DeskMomentService $moments,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->accountProfile;
        $hasXConnected = $user->connectedAccounts()->where('is_active', true)->exists();

        $posts = $user->posts();
        $postedCount = (clone $posts)->where('status', 'posted')->count();
        $draftCount = (clone $posts)->where('status', 'draft')->count();

        $live = $hasXConnected ? $this->liveActivity($user) : null;

        $followersTotal = $live['followers'] ?? 0;
        $followersToday = 0;
        $followersWeek = 0;
        $impressionsTotal = $live['impressions'] ?? (int) (clone $posts)->where('status', 'posted')->sum('impressions');

        $topOpportunity = null;
        if ($profile?->onboarding_completed) {
            $ranked = $this->opportunityScorer->rankedForProfile($profile, 1);
            if (! empty($ranked)) {
                $top = $ranked[0];
                $topOpportunity = [
                    'trend_name' => $top['trend']->name,
                    'score' => $top['opportunity_score'],
                    'suggestion' => $top['suggestion'],
                ];
            }
        }

        return response()->json([
            'stats' => [
                'followers' => [
                    'total' => $followersTotal,
                    'today' => $followersToday,
                    'this_week' => $followersWeek,
                ],
                'impressions' => [
                    'total' => $impressionsTotal,
                    'change_percent' => 0,
                ],
                'engagement_rate' => $live['engagement_rate'] ?? 0,
                'profile_visits' => 0,
                'growth_score' => $profile?->onboarding_completed ? 70 : 0,
                'posts_posted' => $postedCount,
                'posts_draft' => $draftCount,
                'connected_accounts' => $user->connectedAccounts()->where('is_active', true)->count(),
                'preview_metrics' => ! $hasXConnected,
                'top_opportunity' => $topOpportunity,
            ],
            'note' => $hasXConnected
                ? null
                : 'Connect X to load live followers and impressions.',
        ]);
    }

    public function platform(Request $request, string $platform): JsonResponse
    {
        $user = $request->user();
        $profile = $user->accountProfile;
        $setup = PlatformSetup::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->first();

        $connected = $user->connectedAccounts()
            ->where('platform', $platform)
            ->where('is_active', true)
            ->first();

        $posts = $user->posts()->where(function ($query) use ($platform) {
            if ($platform === 'x') {
                $query->where('content_type', 'not like', 'tiktok%')
                    ->where('content_type', 'not like', 'youtube%');
            } else {
                $query->where('content_type', 'like', $platform.'%');
            }
        });

        $drafts = (clone $posts)->where('status', 'draft')->latest()->limit(5)
            ->get(['id', 'content', 'content_type', 'created_at']);

        $preview = $platform !== 'x' && $connected === null;
        $live = $platform === 'x' ? $this->liveActivity($user) : null;
        $seed = crc32($user->id.$platform.($profile?->niche ?? 'general'));

        $metrics = match ($platform) {
            'tiktok' => [
                ['label' => 'Views', 'value' => $preview ? 18000 + ($seed % 140000) : 0, 'sub' => 'Last 7 days'],
                ['label' => 'Followers', 'value' => $preview ? 300 + ($seed % 6000) : 0, 'sub' => '+'.($preview ? ($seed % 60) + 5 : 0).' this week'],
                ['label' => 'Likes', 'value' => $preview ? 1200 + ($seed % 22000) : 0, 'sub' => 'Last 7 days'],
            ],
            'youtube' => [
                ['label' => 'Subscribers', 'value' => $preview ? 150 + ($seed % 3800) : 0, 'sub' => '+'.($preview ? ($seed % 25) + 2 : 0).' this week'],
                ['label' => 'Watch hours', 'value' => $preview ? 40 + ($seed % 900) : 0, 'sub' => 'Last 28 days'],
                ['label' => 'Views', 'value' => $preview ? 2400 + ($seed % 50000) : 0, 'sub' => 'Last 28 days'],
            ],
            default => $live ? [
                ['label' => 'Followers', 'value' => $live['followers'], 'sub' => 'Live from X'],
                ['label' => 'Impressions', 'value' => $live['impressions'], 'sub' => 'Recent posts'],
                ['label' => 'Engagement', 'value' => $live['engagement_rate'], 'sub' => 'Recent posts', 'suffix' => '%'],
            ] : [
                ['label' => 'Followers', 'value' => 0, 'sub' => $connected ? 'Could not load from X' : 'Connect X'],
                ['label' => 'Impressions', 'value' => 0, 'sub' => $connected ? 'Could not load from X' : 'Connect X'],
                ['label' => 'Engagement', 'value' => 0, 'sub' => $connected ? 'Could not load from X' : 'Connect X', 'suffix' => '%'],
            ],
        };

        $ideas = [];
        if ($profile?->onboarding_completed) {
            $recent = (clone $posts)->latest()->limit(6)->pluck('content')
                ->map(fn ($content) => mb_substr(trim((string) $content), 0, 180))
                ->filter()
                ->values()
                ->all();
            $ideas = $this->moments->forProfile($user, $profile, $platform, $recent);
        }

        return response()->json([
            'platform' => $platform,
            'chosen' => in_array($platform, $profile?->platformList() ?? [], true),
            'setup' => [
                'completed' => $setup?->completed_at !== null,
                'goals' => $setup?->goalList() ?? [],
                'settings' => $setup?->settings ?? (object) [],
            ],
            'connected' => $connected ? ['username' => $connected->username] : null,
            'preview' => $preview,
            'metrics' => $metrics,
            'counts' => [
                'drafts' => (clone $posts)->where('status', 'draft')->count(),
                'posted' => (clone $posts)->where('status', 'posted')->count(),
            ],
            'drafts' => $drafts,
            'ideas' => $ideas,
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        $profile = $request->user()->accountProfile;

        if ($profile?->onboarding_completed) {
            $ranked = $this->opportunityScorer->rankedForProfile($profile, 10);

            return response()->json([
                'trends' => collect($ranked)->map(fn ($item) => [
                    'id' => $item['trend']->id,
                    'name' => $item['trend']->name,
                    'score' => $item['trend']->score,
                    'opportunity_score' => $item['opportunity_score'],
                    'growth_rate' => $item['trend']->growth_rate,
                    'competition' => $item['trend']->competition,
                    'suggestion' => $item['suggestion'],
                    'niche_tags' => $item['trend']->niche_tags,
                ]),
            ]);
        }

        $trends = Trend::query()->orderByDesc('score')->limit(10)->get();

        return response()->json(['trends' => $trends]);
    }

    public function opportunities(Request $request): JsonResponse
    {
        $profile = $request->user()->accountProfile;

        if (! $profile?->onboarding_completed) {
            return response()->json(['opportunities' => []]);
        }

        $ranked = $this->opportunityScorer->rankedForProfile($profile, 5);

        return response()->json([
            'opportunities' => collect($ranked)->map(fn ($item) => [
                'trend_id' => $item['trend']->id,
                'name' => $item['trend']->name,
                'opportunity_score' => $item['opportunity_score'],
                'trend_score' => $item['trend']->score,
                'growth_rate' => $item['trend']->growth_rate,
                'competition' => $item['trend']->competition,
                'suggestion' => $item['suggestion'],
            ]),
        ]);
    }

    /**
     * @return array{followers: int, impressions: int, engagement_rate: float, tweets: list<array<string, mixed>>}|null
     */
    private function liveActivity(mixed $user): ?array
    {
        $account = $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return null;
        }

        try {
            return $this->x->accountActivity($account);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
