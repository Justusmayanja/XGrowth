<?php

namespace App\Services\YouTube;

use App\Exceptions\YouTubeOAuthException;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\YouTubeResearch;
use App\Models\YouTubeVideo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class YouTubeResearchRefreshService
{
    private const COSTS = [
        'channel_videos' => 3,
        'niche_search' => 101,
        'comments' => 1,
        'views' => 1,
        'retention' => 1,
    ];

    public function __construct(
        private readonly YouTubeOAuthService $oauth,
        private readonly YouTubeDataClient $data,
        private readonly YouTubeAnalyticsClient $analytics,
        private readonly YouTubeDemandService $demand,
        private readonly YouTubeEngineService $engine,
    ) {}

    public function channelVideos(User $user): void
    {
        $this->refresh($user, 'channel_videos', 'channel', function (string $token, ConnectedAccount $account) {
            $videos = $this->data->channelVideos($token, (string) $account->platform_account_id, 25);
            $this->storeApiListings($account->user, 'channel_videos', $videos);

            return ['count' => count($videos)];
        });
    }

    public function nicheSearch(User $user, string $query): void
    {
        $query = trim($query);
        $this->refresh($user, 'niche_search', strtolower($query), function (string $token, ConnectedAccount $account) use ($query) {
            $videos = $this->data->search($token, $query, 20);
            $this->storeApiListings($account->user, 'niche_search', $videos);

            return ['count' => count($videos), 'query' => $query];
        });
    }

    public function comments(User $user, YouTubeVideo $video, string $youtubeVideoId): void
    {
        $this->refresh($user, 'comments', $video->id.':'.$youtubeVideoId, function (string $token) use ($user, $video, $youtubeVideoId) {
            $comments = $this->data->comments($token, $youtubeVideoId);
            $this->engine->storeComments($user, $video, implode("\n", $comments));
            $this->rememberSourceId($video, $youtubeVideoId);

            return ['count' => count($comments), 'video_id' => $video->id];
        });
    }

    public function views(User $user, YouTubeVideo $video, string $youtubeVideoId): void
    {
        $this->refresh($user, 'views', $video->id.':'.$youtubeVideoId, function (string $token) use ($video, $youtubeVideoId) {
            $results = $this->analytics->videoResults($token, $youtubeVideoId);
            $this->rememberSourceId($video, $youtubeVideoId);
            $this->engine->recordResults($video->fresh(), $results['views'], $results['average_view_percent']);

            return ['video_id' => $video->id, ...$results];
        });
    }

    public function retention(User $user, YouTubeVideo $video, string $youtubeVideoId): void
    {
        $this->refresh($user, 'retention', $video->id.':'.$youtubeVideoId, function (string $token) use ($video, $youtubeVideoId) {
            $retention = $this->analytics->retention($token, $youtubeVideoId);
            if ($retention['points'] < 8) {
                throw new \RuntimeException('YouTube Analytics returned too few retention points to create a lesson.');
            }
            $this->rememberSourceId($video, $youtubeVideoId);
            $this->engine->recordRetention($video->fresh(), $retention['csv'], $retention['duration']);

            return ['video_id' => $video->id, 'points' => $retention['points']];
        });
    }

    /** @return array{budget: int, used: int, remaining: int, resets_on: string, cache_minutes: int, pulls: array<string, mixed>} */
    public function status(User $user): array
    {
        $research = YouTubeResearch::query()->where('user_id', $user->id)->first();
        $used = $research?->quota_date?->isToday() ? (int) $research->quota_used : 0;
        $budget = max(1, (int) config('services.youtube.read_quota_daily', 500));
        $cache = is_array($research?->api_cache) ? $research->api_cache : [];
        $pulls = [];
        foreach ($cache as $key => $entry) {
            if (! is_array($entry) || str_starts_with((string) $key, 'listings.')) {
                continue;
            }
            $pulls[$key] = [
                'refreshed_at' => $entry['refreshed_at'] ?? null,
                'last_attempt_at' => $entry['last_attempt_at'] ?? null,
                'cached_until' => $entry['cached_until'] ?? null,
                'error' => $entry['error'] ?? null,
                'summary' => $entry['summary'] ?? null,
            ];
        }

        return [
            'budget' => $budget,
            'used' => $used,
            'remaining' => max(0, $budget - $used),
            'resets_on' => now()->addDay()->startOfDay()->toDateString(),
            'cache_minutes' => max(1, (int) config('services.youtube.research_cache_minutes', 15)),
            'pulls' => $pulls,
        ];
    }

    private function refresh(User $user, string $action, string $identity, callable $pull): void
    {
        $key = $action.'.'.sha1($identity);
        $research = YouTubeResearch::query()->firstOrCreate(['user_id' => $user->id]);
        $cache = is_array($research->api_cache) ? $research->api_cache : [];
        $current = is_array($cache[$key] ?? null) ? $cache[$key] : [];
        if (($current['identity'] ?? null) === $identity
            && filled($current['refreshed_at'] ?? null)
            && Carbon::parse($current['refreshed_at'])->addMinutes($this->cacheMinutes())->isFuture()) {
            return;
        }

        try {
            $account = $this->account($user);
            $this->takeQuota($research, self::COSTS[$action]);
            $account = $this->oauth->ensureFresh($account);
            $summary = $pull((string) $account->access_token, $account);
            $cache = is_array($research->fresh()->api_cache) ? $research->fresh()->api_cache : [];
            $cache[$key] = [
                'identity' => $identity,
                'refreshed_at' => now()->toIso8601String(),
                'last_attempt_at' => now()->toIso8601String(),
                'cached_until' => now()->addMinutes($this->cacheMinutes())->toIso8601String(),
                'error' => null,
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            $cache = is_array($research->fresh()->api_cache) ? $research->fresh()->api_cache : [];
            $previous = is_array($cache[$key] ?? null) ? $cache[$key] : [];
            $cache[$key] = [
                ...$previous,
                'identity' => $identity,
                'last_attempt_at' => now()->toIso8601String(),
                'error' => $this->message($e),
            ];
            Log::warning('YouTube research refresh failed', [
                'user_id' => $user->id,
                'action' => $action,
                'error' => $e::class,
            ]);
        }

        $research->fresh()->forceFill(['api_cache' => $cache])->save();
    }

    private function takeQuota(YouTubeResearch $research, int $cost): void
    {
        $today = now()->toDateString();
        $used = $research->quota_date?->toDateString() === $today ? (int) $research->quota_used : 0;
        $budget = max(1, (int) config('services.youtube.read_quota_daily', 500));
        if ($used + $cost > $budget) {
            throw ValidationException::withMessages([
                'quota' => ["The YouTube read budget is spent for today ({$used}/{$budget}). Cached research is still available."],
            ]);
        }
        $research->forceFill([
            'quota_date' => $today,
            'quota_used' => $used + $cost,
        ])->save();
    }

    private function account(User $user): ConnectedAccount
    {
        $account = ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw ValidationException::withMessages([
                'youtube' => ['Connect the read-only YouTube channel before refreshing research.'],
            ]);
        }

        return $account;
    }

    /** @param list<array<string, mixed>> $videos */
    private function storeApiListings(User $user, string $source, array $videos): void
    {
        $research = YouTubeResearch::query()->firstOrCreate(['user_id' => $user->id]);
        $cache = is_array($research->api_cache) ? $research->api_cache : [];
        $cache['listings.'.$source] = array_values($videos);
        $research->forceFill(['api_cache' => $cache])->save();
    }

    private function rememberSourceId(YouTubeVideo $video, string $youtubeVideoId): void
    {
        $package = $video->package ?? [];
        $package['research_source'] = ['youtube_video_id' => $youtubeVideoId];
        $video->forceFill(['package' => $package])->save();
    }

    private function message(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->first() ?? 'The YouTube read could not start.';
        }
        if ($e instanceof YouTubeOAuthException && $e->reason === 'needs_reconnect') {
            return 'Reconnect YouTube before refreshing research.';
        }

        return trim($e->getMessage()) ?: 'YouTube could not refresh this research right now.';
    }

    private function cacheMinutes(): int
    {
        return max(1, (int) config('services.youtube.research_cache_minutes', 15));
    }
}
