<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;
use App\Models\User;
use App\Models\YouTubeResearch;

/**
 * Ideas come from videos that beat their own channel's median, and from
 * questions the comments keep asking. A past average-view percent is not a score.
 */
class YouTubeDemandService
{
    public function __construct(private readonly YouTubeViralRanker $ranker) {}

    /**
     * @param  list<array{channel: string, title: string, views: int, url?: string, duration?: int}>  $videos
     */
    public function storeListings(User $user, array $videos): YouTubeResearch
    {
        $research = YouTubeResearch::query()->firstOrNew(['user_id' => $user->id]);
        $research->listings = array_values($videos);
        $research->save();

        return $research;
    }

    /**
     * @param  list<array{question: string, count: int}>  $topics
     */
    public function storeTopics(User $user, array $topics): void
    {
        if ($topics === []) {
            return;
        }
        $research = YouTubeResearch::query()->firstOrNew(['user_id' => $user->id]);
        $existing = is_array($research->topics) ? $research->topics : [];
        foreach ($topics as $topic) {
            $question = trim((string) ($topic['question'] ?? ''));
            if ($question === '') {
                continue;
            }
            $found = false;
            foreach ($existing as &$row) {
                if (strcasecmp((string) ($row['question'] ?? ''), $question) === 0) {
                    $row['count'] = max((int) ($row['count'] ?? 0), (int) ($topic['count'] ?? 0));
                    $found = true;
                    break;
                }
            }
            unset($row);
            if (! $found) {
                $existing[] = ['question' => $question, 'count' => (int) ($topic['count'] ?? 2)];
            }
        }
        $research->topics = $existing;
        $research->save();
    }

    /**
     * @return array{working: list<array<string, mixed>>, shared: string, makeable: list<array<string, mixed>>, skipped: list<array{channel: string, count: int}>, note: string, week: list<array<string, mixed>>}
     */
    public function research(AccountProfile $profile, User $user): array
    {
        $stored = YouTubeResearch::query()->where('user_id', $user->id)->first();
        $listings = is_array($stored?->listings) ? $stored->listings : [];
        $apiCache = is_array($stored?->api_cache) ? $stored->api_cache : [];
        foreach ($apiCache as $key => $cachedListings) {
            if (str_starts_with((string) $key, 'listings.') && is_array($cachedListings)) {
                $listings = [...$listings, ...$cachedListings];
            }
        }
        $listings = collect($listings)
            ->unique(fn ($video) => (string) ($video['youtube_video_id'] ?? $video['url'] ?? '').'|'.strtolower((string) ($video['channel'] ?? '').'|'.(string) ($video['title'] ?? '')))
            ->values()
            ->all();
        $ranked = $this->ranker->rank($listings);
        $top = array_slice($ranked['outliers'], 0, 5);
        $makeable = $this->ranker->makeable($top, $profile);

        $week = [];
        foreach ($makeable as $video) {
            $week[] = [
                'topic' => (string) $video['idea'],
                'query' => $profile->resolvedNiche(),
                'kind' => (string) ($video['formula'] ?? 'viral'),
                'reason' => 'It beat '.$video['channel']."'s median by ".$video['multiple'].'x. The title reads as '.$video['formula'].'. This channel can make that shape with '.$video['have'].'. The formula describes the title, not the cause.',
                'score' => min(100, round(((float) $video['multiple']) * 25, 1)),
                'source' => 'viral',
                'formula' => $video['formula'],
                'multiple' => $video['multiple'],
            ];
        }

        foreach (is_array($stored?->topics) ? $stored->topics : [] as $topic) {
            $question = trim((string) ($topic['question'] ?? ''));
            $count = (int) ($topic['count'] ?? 0);
            if ($question === '' || $count < 2) {
                continue;
            }
            $week[] = [
                'topic' => $question,
                'query' => $profile->resolvedNiche(),
                'kind' => 'question',
                'reason' => 'Asked '.$count.' times in the comments, and it stays inside the account description.',
                'score' => min(100, $count * 20),
                'source' => 'comment',
                'formula' => 'The Question',
                'multiple' => null,
            ];
        }

        usort($week, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $week = array_slice($week, 0, 3);

        return [
            'working' => $top,
            'shared' => $ranked['shared'],
            'makeable' => array_map(fn (array $video) => [
                'title' => $video['title'],
                'multiple' => $video['multiple'],
                'formula' => $video['formula'],
                'channel' => $video['channel'],
                'idea' => $video['idea'],
                'have' => $video['have'],
                'voice' => $video['voice'],
            ], $makeable),
            'skipped' => $ranked['skipped'],
            'note' => $listings === []
                ? 'Public listings are not in yet. Nothing here is scored as research until a channel has at least four public videos.'
                : $ranked['note'],
            'week' => $week,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ranked(AccountProfile $profile, string $format, User $user, int $limit = 3): array
    {
        unset($format);

        return array_slice($this->research($profile, $user)['week'], 0, $limit);
    }

    /** @return array{budget: int, used: int, remaining: int, resets_on: string, cache_minutes: int, pulls: array<string, mixed>} */
    public function refreshStatus(User $user): array
    {
        $research = YouTubeResearch::query()->where('user_id', $user->id)->first();
        $used = $research?->quota_date?->isToday() ? (int) $research->quota_used : 0;
        $budget = max(1, (int) config('services.youtube.read_quota_daily', 500));
        $pulls = [];
        foreach (is_array($research?->api_cache) ? $research->api_cache : [] as $key => $entry) {
            if (! is_array($entry) || str_starts_with((string) $key, 'listings.')) {
                continue;
            }
            [$action] = explode('.', (string) $key, 2);
            $pulls[$action] = [
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
}
