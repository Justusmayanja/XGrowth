<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetup;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\GrowthAgent\AccountDeskService;
use App\Services\GrowthAgent\XGoalPlan;
use App\Services\TikTok\TikTokEngineService;
use App\Services\TrendEngine\TrendEngineService;
use App\Services\XApi\XApiService;
use App\Services\YouTube\YouTubeEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private readonly XApiService $x,
        private readonly TrendEngineService $trendEngine,
        private readonly YouTubeEngineService $youtube,
        private readonly TikTokEngineService $tiktok,
        private readonly ApiSpendService $budget,
        private readonly AccountDeskService $desk,
        private readonly XGoalPlan $goals,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $xAccount = $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();
        $hasXConnected = $xAccount !== null;

        $posts = $user->posts();
        $postedCount = (clone $posts)->where('status', 'posted')->count();
        $draftCount = (clone $posts)->where('status', 'draft')->count();

        $live = $hasXConnected ? $this->liveActivity($user) : null;

        $followersTotal = $live['followers'] ?? 0;
        $followersToday = 0;
        $followersWeek = 0;
        $impressionsTotal = $live['impressions'] ?? (int) (clone $posts)->where('status', 'posted')->sum('impressions');

        $topOpportunity = null;
        if ($xAccount) {
            try {
                $topics = $this->trendEngine->topicsForPage($user)['trends'];
                if ($topics !== []) {
                    $topOpportunity = [
                        'trend_name' => $topics[0]['name'],
                        'score' => null,
                        'suggestion' => $topics[0]['draft'] ?? '',
                    ];
                }
            } catch (Throwable) {
                $topOpportunity = null;
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
                'growth_score' => $live ? (int) round($live['engagement_rate']) : 0,
                'posts_posted' => $postedCount,
                'posts_draft' => $draftCount,
                'connected_accounts' => $user->connectedAccounts()->where('is_active', true)->count(),
                'preview_metrics' => ! $hasXConnected,
                'top_opportunity' => $topOpportunity,
            ],
            'note' => $hasXConnected
                ? null
                : 'Connect X to load followers and impressions.',
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

        $selected = $platform === 'x'
            ? $this->desk->select($user, is_numeric($request->query('account')) ? (int) $request->query('account') : null)
            : null;
        $connected = $selected ?? $user->connectedAccounts()
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

        $purpose = $platform === 'x'
            ? $this->desk->purpose($user, $selected)
            : trim((string) ($profile?->description ?? '').' '.(string) ($profile?->audience ?? '').' '.(string) ($profile?->resolvedNiche() ?? ''));
        $postQuery = clone $posts;
        if ($platform === 'x' && $selected) {
            $primaryId = $this->desk->accounts($user)->first()?->id;
            $postQuery->where(function ($query) use ($selected, $primaryId) {
                $query->where('connected_account_id', $selected->id);
                if ((int) $selected->id === (int) $primaryId) {
                    $query->orWhereNull('connected_account_id');
                }
            });
        }
        $drafts = (clone $postQuery)->where('status', 'draft')->latest()->limit(12)
            ->get(['id', 'content', 'content_type', 'created_at']);
        if ($platform === 'x') {
            $drafts = $drafts
                ->reject(fn ($draft) => $this->x->isOutsideTheAccount((string) $draft->content, $purpose))
                ->take(5)
                ->values();
        }

        $preview = $platform !== 'x' && $connected === null;
        $live = $platform === 'x' ? $this->liveActivity($user, $selected) : null;
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
            default => $live && ($live['metrics_loaded'] ?? false) ? $this->xMetrics($live, $this->budget->showsFullDesk($user->id)) : ($live ? [
                ['label' => 'Followers', 'value' => $live['followers'], 'sub' => 'Results for the last seven days'],
                ['label' => 'Reactions', 'value' => null, 'sub' => 'Could not load likes from X'],
                ['label' => 'Likes', 'value' => null, 'sub' => 'Could not load likes from X'],
            ] : [
                ['label' => 'Followers', 'value' => null, 'sub' => $connected ? 'Could not load from X' : 'Connect X'],
                ['label' => 'Impressions', 'value' => null, 'sub' => $connected ? 'Could not load from X' : 'Connect X'],
                ['label' => 'Engagement', 'value' => null, 'sub' => $connected ? 'Could not load from X' : 'Connect X', 'suffix' => '%'],
            ]),
        };

        $ideas = [];
        $ideasNote = null;
        if ($platform === 'x' && $connected) {
            try {
                $topicPage = $this->trendEngine->topicsForPage($user, $selected);
                $ideasNote = $topicPage['note'] ?? null;
                foreach (array_slice($topicPage['trends'], 0, 5) as $topic) {
                    $name = trim((string) ($topic['name'] ?? ''));
                    $draft = trim((string) ($topic['draft'] ?? ''));
                    if ($name === '' || ! $this->x->isEnglishPhrase($name)) {
                        continue;
                    }
                    if ($this->x->isOutsideTheAccount($name.' '.$draft, $purpose)) {
                        continue;
                    }
                    if ($draft !== '' && ! $this->x->isEnglishPhrase($draft)) {
                        continue;
                    }
                    $ideas[] = [
                        'name' => $name,
                        'suggestion' => $draft,
                    ];
                }
            } catch (Throwable) {
                $ideas = [];
            }
        }

        $youtubeDesk = [
            'ideas' => [],
            'pipeline' => [],
            'series' => null,
            'week' => [],
            'niche' => null,
            'audit' => null,
        ];
        if ($platform === 'youtube' && $profile && $setup?->completed_at) {
            $youtubeDesk = $this->youtube->desk($user);
            $ideas = $youtubeDesk['ideas'];
        }

        $settings = is_array($setup?->settings) ? $setup->settings : [];
        if ($platform === 'x' && $profile) {
            $settings['automation_level'] = (int) ($profile->automation_level ?? ($settings['automation_level'] ?? 1));
            $settings['mode'] = $profile->mode ?? ($settings['mode'] ?? 'reactive');
            $settings['auto_post_enabled'] = (bool) $profile->auto_post_enabled;
            $settings['auto_reply_enabled'] = (bool) $profile->auto_reply_enabled;
            $settings['auto_engage_enabled'] = (bool) $profile->auto_engage_enabled;
            $settings['engine_paused'] = (bool) $profile->engine_paused;
            $settings['max_silence_minutes'] = (int) ($profile->max_silence_minutes ?? 60);
        }

        $payload = [
            'platform' => $platform,
            'chosen' => in_array($platform, $profile?->platformList() ?? [], true),
            'setup' => [
                'completed' => $setup?->completed_at !== null,
                'goals' => $setup?->goalList() ?? [],
                'settings' => $settings === [] ? (object) [] : $settings,
            ],
            'connected' => $connected ? ['username' => $connected->username] : null,
            'preview' => $preview,
            'metrics' => $metrics,
            'counts' => [
                'drafts' => $platform === 'x'
                    ? (clone $postQuery)->where('status', 'draft')->pluck('content')
                        ->reject(fn ($content) => $this->x->isOutsideTheAccount((string) $content, $purpose))
                        ->count()
                    : (clone $posts)->where('status', 'draft')->count(),
                'posted' => (clone $postQuery)->where('status', 'posted')->count(),
            ],
            'drafts' => $drafts,
            'ideas' => $ideas,
            'ideas_note' => $ideasNote,
            'pace' => $platform === 'x' ? [
                'ceiling' => $this->budget->dailyPostTarget($user->id),
                'low' => (int) (config('billing.platforms.x.tiers.'.$this->budget->tier($user->id).'.api.post_low') ?? 6),
                'high' => (int) (config('billing.platforms.x.tiers.'.$this->budget->tier($user->id).'.api.post_high') ?? 8),
            ] : null,
            'accounts' => $platform === 'x' ? [
                'connected' => $this->desk->accounts($user)->count(),
                'included' => $this->budget->accountLimit($user->id),
            ] : null,
            'judgment' => $platform === 'x' ? $this->desk->judgment($user, $selected) : null,
            'goal_progress' => $platform === 'x' && $profile ? $this->goals->progress($profile, $live) : null,
            'earned' => $platform === 'x' ? $this->desk->earned($live) : null,
            'reply_record' => $platform === 'x' ? $this->desk->replyRecord($user, $selected) : null,
            'voices' => $platform === 'x' ? $this->desk->voices($user, $selected) : null,
            'pipeline' => $youtubeDesk['pipeline'],
            'series' => $youtubeDesk['series'],
            'week' => $youtubeDesk['week'],
            'niche' => $youtubeDesk['niche'],
            'audit' => $youtubeDesk['audit'],
        ];

        if ($platform === 'tiktok') {
            $payload['tiktok'] = $this->tiktok->desk($user);
        }

        return response()->json($payload);
    }

    public function trends(Request $request): JsonResponse
    {
        return response()->json($this->trendEngine->topicsForPage($request->user()));
    }

    public function opportunities(Request $request): JsonResponse
    {
        $account = $request->user()->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json([
                'opportunities' => [],
                'note' => 'Connect X to load topics from the account.',
            ]);
        }

        try {
            $page = $this->trendEngine->topicsForPage($request->user());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'opportunities' => [],
                'note' => 'X did not return the home timeline. '.$e->getMessage(),
            ]);
        }

        return response()->json([
            'opportunities' => array_map(fn (array $topic) => [
                'name' => $topic['name'],
                'figures' => '',
                'suggestion' => (string) ($topic['draft'] ?? ''),
            ], array_slice($page['trends'], 0, 5)),
            'note' => $page['note'],
        ]);
    }

    /**
     * @param  array{ranked_for_niche?: bool}  $live
     */
    private function impressionBasis(array $live): string
    {
        if ($live['impressions_held'] ?? false) {
            return 'Kept from yesterday. X did not send a new view count.';
        }

        return ($live['ranked_for_niche'] ?? false)
            ? 'Posts and comments, ranked for your niche'
            : 'Recent posts and comments';
    }

    private function sinceYesterday(mixed $change): string
    {
        if (! is_numeric($change)) {
            return '';
        }

        $change = (int) $change;
        if ($change > 0) {
            return ' +'.number_format($change).' since yesterday';
        }
        if ($change < 0) {
            return ' '.number_format($change).' since yesterday';
        }

        return ' No change since yesterday';
    }

    /**
     * @return array{followers: int, impressions: int, engagement_rate: float, ranked_for_niche: bool, tweets: list<array<string, mixed>>}|null
     */
    /**
     * @param  array<string, mixed>  $live
     * @return list<array{label: string, value: int, sub: string}>
     */
    private function xMetrics(array $live, bool $full): array
    {
        $sub = $full ? 'From the latest read' : 'Results for the last seven days';
        $cards = [
            ['label' => 'Followers', 'value' => (int) $live['followers'], 'sub' => $sub],
            ($live['views_known'] ?? false)
                ? ['label' => 'Impressions', 'value' => (int) $live['impressions'], 'sub' => $sub]
                : ['label' => 'Reactions', 'value' => (int) ($live['engagements'] ?? 0), 'sub' => $sub],
            ['label' => 'Likes', 'value' => (int) ($live['likes'] ?? 0), 'sub' => $sub],
        ];

        if ($full) {
            $cards[] = ['label' => 'Replies', 'value' => (int) ($live['replies'] ?? 0), 'sub' => $sub];
            $cards[] = ['label' => 'Reposts', 'value' => (int) ($live['reposts'] ?? 0), 'sub' => $sub];
            $cards[] = ['label' => 'Profile visits', 'value' => (int) ($live['profile_clicks'] ?? 0), 'sub' => $sub];
        }

        return $cards;
    }

    private function liveActivity(mixed $user, mixed $account = null): ?array
    {
        $account ??= $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return null;
        }

        try {
            return $this->x->accountActivity($account, $account->nicheFor($user->accountProfile));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
