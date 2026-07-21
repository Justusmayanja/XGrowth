<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trend;
use App\Services\GrowthAgent\GrowthAgentService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly OpportunityScorerService $opportunityScorer,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->accountProfile;
        $hasXConnected = $user->connectedAccounts()->where('is_active', true)->exists();

        $posts = $user->posts();
        $postedCount = (clone $posts)->where('status', 'posted')->count();
        $draftCount = (clone $posts)->where('status', 'draft')->count();

        $preview = ! $hasXConnected;
        $seed = crc32($user->id.($profile?->niche ?? 'general'));

        $followersTotal = $preview ? 840 + ($seed % 4200) : 0;
        $followersToday = $preview ? ($seed % 9) + 1 : 0;
        $followersWeek = $preview ? ($seed % 35) + 8 : 0;
        $impressionsTotal = $preview
            ? 12000 + ($seed % 80000)
            : (int) (clone $posts)->where('status', 'posted')->sum('impressions');

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
                    'change_percent' => $preview ? round(8 + ($seed % 22), 1) : 0,
                ],
                'engagement_rate' => $preview ? round(2.4 + ($seed % 30) / 10, 1) : 0,
                'profile_visits' => $preview ? 120 + ($seed % 400) : 0,
                'growth_score' => $profile?->onboarding_completed ? min(95, 68 + ($seed % 25)) : 0,
                'posts_posted' => $postedCount,
                'posts_draft' => $draftCount,
                'connected_accounts' => $user->connectedAccounts()->where('is_active', true)->count(),
                'preview_metrics' => $preview,
                'top_opportunity' => $topOpportunity,
            ],
            'note' => $preview
                ? 'Preview metrics shown until your X account is connected.'
                : null,
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        if (Trend::count() === 0) {
            app(TrendEngineService::class)->seedMockTrends();
        }

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

        if (Trend::count() === 0) {
            app(TrendEngineService::class)->seedMockTrends();
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
}
