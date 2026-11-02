<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XApi\XApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly XApiService $x,
    ) {}

    public function growth(Request $request): JsonResponse
    {
        $account = $request->user()->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json($this->emptyGrowth(
                true,
                'Connect X to load live totals from the account.'
            ));
        }

        try {
            $activity = $this->x->accountActivity($account, $request->user()->accountProfile?->resolvedNiche());
        } catch (Throwable $e) {
            report($e);

            return response()->json($this->emptyGrowth(false, 'X did not return account totals. '.$e->getMessage()));
        }

        $byDay = [];
        foreach ($activity['tweets'] as $tweet) {
            if (($tweet['created_at'] ?? '') === '') {
                continue;
            }
            $day = Carbon::parse($tweet['created_at'])->toDateString();
            $byDay[$day]['impressions'] = ($byDay[$day]['impressions'] ?? 0) + $tweet['impressions'];
            $byDay[$day]['engagements'] = ($byDay[$day]['engagements'] ?? 0)
                + $tweet['likes'] + $tweet['reposts'] + $tweet['replies'];
            $byDay[$day]['profile_clicks'] = ($byDay[$day]['profile_clicks'] ?? 0) + (int) ($tweet['profile_clicks'] ?? 0);
        }

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $impressions = $byDay[$date]['impressions'] ?? 0;
            $engagements = $byDay[$date]['engagements'] ?? 0;
            $series[] = [
                'date' => $date,
                'followers' => $activity['followers'],
                'impressions' => $impressions,
                'profile_clicks' => $byDay[$date]['profile_clicks'] ?? 0,
                'engagement_rate' => $impressions > 0 ? round(($engagements / $impressions) * 100, 1) : 0,
            ];
        }

        $topPosts = collect($activity['tweets'])
            ->sortByDesc('impressions')
            ->take(3)
            ->map(fn (array $tweet) => [
                'id' => $tweet['id'],
                'content' => $tweet['content'],
                'impressions' => $tweet['impressions'],
                'likes' => $tweet['likes'],
                'reposts' => $tweet['reposts'],
                'replies' => $tweet['replies'],
                'posted_at' => $tweet['created_at'],
            ])
            ->values()
            ->all();

        $hours = [];
        foreach ($activity['tweets'] as $tweet) {
            if (($tweet['created_at'] ?? '') === '') {
                continue;
            }
            $hour = Carbon::parse($tweet['created_at'])->format('ga');
            $hours[$hour] = ($hours[$hour] ?? 0) + 1;
        }
        arsort($hours);
        $insights = $hours === [] ? [] : [[
            'title' => 'When these posts went out',
            'detail' => 'Most of the recent posts were sent around '.array_key_first($hours).'.',
            'type' => 'timing',
        ]];

        $viewsKnown = (bool) ($activity['views_known'] ?? false);
        $week = array_slice($series, -7);

        return response()->json([
            'preview_metrics' => false,
            'views_known' => $viewsKnown,
            'summary' => [
                'followers' => $activity['followers'],
                'followers_change_7d' => null,
                'impressions_7d' => $viewsKnown ? array_sum(array_column($week, 'impressions')) : null,
                'reactions_7d' => (int) ($activity['engagements'] ?? array_sum(array_column($week, 'engagements'))),
                'likes_7d' => (int) ($activity['likes'] ?? 0),
                'replies_7d' => (int) ($activity['replies'] ?? 0),
                'reposts_7d' => (int) ($activity['reposts'] ?? 0),
                'avg_engagement_rate' => $viewsKnown ? round(array_sum(array_column($week, 'engagement_rate')) / 7, 1) : null,
                'profile_visits_7d' => $viewsKnown ? array_sum(array_column($week, 'profile_clicks')) : null,
            ],
            'series' => $series,
            'top_posts' => $topPosts,
            'insights' => $insights,
            'note' => 'Results for the last seven days. These figures are fetched once and again when the week ends.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGrowth(bool $preview, string $note): array
    {
        return [
            'preview_metrics' => $preview,
            'views_known' => false,
            'summary' => [
                'followers' => null,
                'followers_change_7d' => null,
                'impressions_7d' => null,
                'avg_engagement_rate' => null,
                'profile_visits_7d' => null,
            ],
            'series' => [],
            'top_posts' => [],
            'insights' => [],
            'note' => $note,
        ];
    }
}
