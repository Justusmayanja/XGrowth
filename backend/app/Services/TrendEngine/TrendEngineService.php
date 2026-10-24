<?php

namespace App\Services\TrendEngine;

use App\Models\Trend;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\AICore\AICoreService;
use App\Services\XApi\XApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TrendEngineService
{
    public function __construct(
        private readonly PlatformSettingsService $settings,
        private readonly XApiService $x,
        private readonly AICoreService $ai,
    ) {}

    /**
     * Topics from this user's X account, shaped for the draft engine.
     * Returns null when X is not connected. Returns an empty list when X had nothing.
     * Does not call an AI model.
     *
     * @return list<array{trend: Trend, opportunity_score: float, suggestion: string}>|null
     */
    public function rankedFromAccount(User $user): ?array
    {
        $account = $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return null;
        }

        try {
            $live = $this->x->accountTopics($account);
        } catch (Throwable $e) {
            Log::warning('X topics were not available for drafting', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($live['topics'] === []) {
            return [];
        }

        $magnitudes = array_map(function (array $topic): int {
            return max((int) ($topic['impressions'] ?? 0), (int) ($topic['posts'] ?? 0), (int) ($topic['likes'] ?? 0));
        }, $live['topics']);
        $max = max(1, ...$magnitudes);

        $ranked = [];
        foreach ($live['topics'] as $index => $topic) {
            $magnitude = $magnitudes[$index] ?? 0;
            $score = round(50 + (50 * ($magnitude / $max)), 1);
            $trend = new Trend([
                'name' => $topic['name'],
                'score' => $score,
                'velocity' => 0,
                'growth_rate' => 0,
                'competition' => 'medium',
                'niche_tags' => [],
                'metadata' => ['source' => 'x'],
                'detected_at' => now(),
            ]);

            $ranked[] = [
                'trend' => $trend,
                'opportunity_score' => $score,
                'suggestion' => $topic['figures'],
            ];
        }

        return $ranked;
    }

    /**
     * Three to five current topics for the Topics page, each with a post from this account.
     * Live names come from X. The writing model runs once, then the result is cached.
     *
     * @return array{trends: list<array{name: string, angle: string, draft: string}>, source: string|null, note: string}
     */
    public function topicsForPage(User $user): array
    {
        $account = $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return [
                'trends' => [],
                'source' => null,
                'note' => 'Connect X to load topics from the account.',
            ];
        }

        try {
            $live = $this->x->accountTopics($account);
        } catch (Throwable $e) {
            Log::warning('X topics were not available for the topics page', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'trends' => [],
                'source' => null,
                'note' => 'X did not return topics. '.$e->getMessage(),
            ];
        }

        $names = [];
        foreach (array_slice($live['topics'], 0, 12) as $topic) {
            $name = trim((string) ($topic['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return [
                'trends' => [],
                'source' => $live['source'],
                'note' => 'X did not return topics for this account.',
            ];
        }

        $cacheKey = 'x-topic-drafts:'.$user->id.':'.md5(implode("\n", $names));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['trends']) && is_array($cached['trends'])) {
            return $cached;
        }

        $drafted = [];
        if ($this->ai->isConfigured()) {
            try {
                $drafted = array_values(array_filter(
                    $this->ai->draftTrendingTopics($user, $names, $user->accountProfile),
                    fn (array $item) => $this->x->isEnglishPhrase($item['name']) && $this->x->isEnglishPhrase($item['draft'])
                ));
            } catch (Throwable $e) {
                Log::warning('Topic drafts were not written', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $wrote = $drafted !== [];
        if (! $wrote) {
            foreach (array_slice($names, 0, 5) as $name) {
                $drafted[] = ['name' => $name, 'angle' => '', 'draft' => ''];
            }
        }

        $payload = [
            'trends' => $drafted,
            'source' => $live['source'],
            'note' => $wrote
                ? 'A few conversations happening now, with a post written from this account.'
                : 'These are on X right now. A post could not be written this time.',
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes($wrote ? 30 : 10));

        return $payload;
    }

    /**
     * Used when a draft needs a conversation and this X account did not return topics.
     * A connected account is never sent through the AI search.
     */
    public function ensureCurrentTrends(User $user): void
    {
        $fromAccount = $this->rankedFromAccount($user);
        if (is_array($fromAccount) && $fromAccount !== []) {
            return;
        }

        if ($this->hasFreshLiveTrends()) {
            return;
        }

        if ($this->x->configured()) {
            $this->fetchGlobalTrends(force: true);
            if ($this->hasFreshLiveTrends()) {
                return;
            }
        }

        if (! $this->settings->getBool('web_search_enabled', true) || ! $this->ai->isConfigured()) {
            return;
        }

        $cached = Cache::get('trends:live_topics');
        if (is_array($cached) && $cached !== []) {
            $this->rememberLiveConversations($cached);

            return;
        }

        try {
            $topics = $this->ai->currentConversations($user);
        } catch (Throwable $e) {
            Log::warning('Live conversation search failed', ['error' => $e->getMessage()]);

            return;
        }

        if ($topics === []) {
            return;
        }

        Cache::put('trends:live_topics', $topics, now()->addMinutes(45));
        $this->rememberLiveConversations($topics);
    }

    /**
     * @param  list<array{name?: string, detail?: string}>  $topics
     */
    public function rememberLiveConversations(array $topics): void
    {
        $stored = [];

        foreach (array_slice($topics, 0, 5) as $index => $topic) {
            $name = trim((string) ($topic['name'] ?? ''));
            $slug = Str::slug($name);
            if ($name === '' || $slug === '') {
                continue;
            }

            $score = max(55, 94 - ($index * 6));
            $stored[] = Trend::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'score' => $score,
                    'velocity' => 0,
                    'growth_rate' => 20,
                    'competition' => $score >= 80 ? 'high' : 'medium',
                    'niche_tags' => [],
                    'detected_at' => now(),
                    'metadata' => [
                        'source' => 'live',
                        'detail' => mb_substr(trim((string) ($topic['detail'] ?? '')), 0, 180),
                    ],
                ]
            );
        }

        if ($stored === []) {
            return;
        }

        $kept = array_map(fn (Trend $trend) => $trend->id, $stored);
        Trend::query()->where('metadata->source', 'mock')->delete();
        Trend::query()->where('metadata->source', 'live')->whereNotIn('id', $kept)->delete();
    }

    private function hasFreshLiveTrends(): bool
    {
        return Trend::query()
            ->where('detected_at', '>=', now()->subMinutes(45))
            ->where(function ($query) {
                $query->where('metadata->source', 'x')
                    ->orWhere('metadata->source', 'live');
            })
            ->exists();
    }

    /**
     * Refresh the shared trend catalog from X. Called on the admin-configured schedule.
     */
    public function fetchGlobalTrends(bool $force = false): array
    {
        if (! $force && ! $this->shouldFetchNow()) {
            Log::info('Trend fetch skipped — outside scheduled window or already fetched recently');

            return Trend::query()->orderByDesc('score')->limit(20)->get()->all();
        }

        if (! $this->x->configured()) {
            Log::info('Trend fetch skipped — X app credentials are not set');

            return Trend::query()->orderByDesc('score')->limit(20)->get()->all();
        }

        try {
            $topics = $this->x->trendingTopics();
        } catch (Throwable $e) {
            Log::warning('X trend fetch failed', ['error' => $e->getMessage()]);

            return Trend::query()->orderByDesc('score')->limit(20)->get()->all();
        }

        if ($topics === []) {
            return Trend::query()->orderByDesc('score')->limit(20)->get()->all();
        }

        Trend::query()->where('metadata->source', 'mock')->delete();

        $max = max(1, ...array_map(fn (array $topic) => $topic['tweet_count'], $topics));
        $seeded = [];

        foreach (array_slice($topics, 0, 15) as $item) {
            $slug = Str::slug($item['name']);
            if ($slug === '') {
                continue;
            }

            $score = $item['tweet_count'] > 0 ? round(40 + (60 * ($item['tweet_count'] / $max)), 1) : 50;
            $seeded[] = Trend::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $item['name'],
                    'score' => $score,
                    'velocity' => 0,
                    'growth_rate' => 0,
                    'competition' => $score >= 80 ? 'high' : ($score >= 60 ? 'medium' : 'low'),
                    'niche_tags' => [],
                    'detected_at' => now(),
                    'metadata' => ['source' => 'x'],
                ]
            );
        }

        Cache::put('trends:last_fetched_at', now()->timestamp, now()->addDay());

        return $seeded;
    }

    public function shouldFetchNow(): bool
    {
        $times = $this->settings->getFetchTimes();
        if ($times === []) {
            return true;
        }

        $now = now();
        foreach ($times as $slot) {
            $slotTime = \Illuminate\Support\Carbon::parse($slot);
            $diff = abs($now->diffInMinutes($slotTime, false));
            if ($diff <= 30) {
                return ! $this->fetchedRecently();
            }
        }

        return false;
    }

    private function fetchedRecently(): bool
    {
        $last = Cache::get('trends:last_fetched_at');
        if (! is_numeric($last)) {
            return false;
        }

        $cooldownHours = max(1, $this->settings->getInt('trend_fetch_cooldown_hours', 4));

        return now()->diffInMinutes(\Illuminate\Support\Carbon::createFromTimestamp((int) $last)) < ($cooldownHours * 60);
    }

    public function scoreTrend(array $trend, ?string $niche): float
    {
        $base = (float) ($trend['score'] ?? 0);
        $tags = $trend['niche_tags'] ?? [];

        if ($niche && in_array($niche, $tags, true)) {
            return min(100, $base + 8);
        }

        return $base;
    }

    public function seedMockTrends(): array
    {
        $catalog = [
            ['name' => 'Transfer deadline drama', 'slug' => 'transfer-deadline-drama', 'score' => 92, 'growth_rate' => 34.5, 'competition' => 'high', 'niche_tags' => ['football']],
            ['name' => 'Match day reactions heating up', 'slug' => 'match-day-reactions', 'score' => 88, 'growth_rate' => 28.2, 'competition' => 'medium', 'niche_tags' => ['football']],
            ['name' => 'New product launch buzz', 'slug' => 'product-launch-buzz', 'score' => 85, 'growth_rate' => 22.1, 'competition' => 'medium', 'niche_tags' => ['technology', 'business']],
            ['name' => 'Startup funding round talk', 'slug' => 'startup-funding-talk', 'score' => 79, 'growth_rate' => 18.4, 'competition' => 'low', 'niche_tags' => ['business', 'technology']],
            ['name' => 'Market volatility debate', 'slug' => 'market-volatility-debate', 'score' => 86, 'growth_rate' => 31.0, 'competition' => 'high', 'niche_tags' => ['crypto', 'business']],
            ['name' => 'Morning motivation thread', 'slug' => 'morning-motivation-thread', 'score' => 74, 'growth_rate' => 12.6, 'competition' => 'low', 'niche_tags' => ['motivation']],
            ['name' => 'Meme format going viral', 'slug' => 'meme-format-viral', 'score' => 91, 'growth_rate' => 45.3, 'competition' => 'high', 'niche_tags' => ['memes', 'entertainment']],
            ['name' => 'Local city news breaking', 'slug' => 'local-city-news', 'score' => 83, 'growth_rate' => 26.7, 'competition' => 'medium', 'niche_tags' => ['local_news']],
            ['name' => 'Industry hot take thread', 'slug' => 'industry-hot-take', 'score' => 77, 'growth_rate' => 15.9, 'competition' => 'medium', 'niche_tags' => ['technology', 'business', 'crypto']],
            ['name' => 'Weekend engagement poll', 'slug' => 'weekend-engagement-poll', 'score' => 68, 'growth_rate' => 9.4, 'competition' => 'low', 'niche_tags' => ['football', 'motivation', 'memes', 'entertainment']],
        ];

        $seeded = [];

        foreach ($catalog as $item) {
            $trend = Trend::updateOrCreate(
                ['slug' => $item['slug']],
                [
                    ...$item,
                    'velocity' => round($item['growth_rate'] / 3, 2),
                    'detected_at' => now(),
                    'metadata' => ['source' => 'mock'],
                ]
            );
            $seeded[] = $trend;
        }

        return $seeded;
    }
}
