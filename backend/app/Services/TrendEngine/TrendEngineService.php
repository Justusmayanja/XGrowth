<?php

namespace App\Services\TrendEngine;

use App\Models\Trend;
use Illuminate\Support\Str;

class TrendEngineService
{
    /**
     * Global trend monitor — runs every 2-5 minutes via scheduler.
     * Uses mock data until X API integration (final stage).
     */
    public function fetchGlobalTrends(): array
    {
        return $this->seedMockTrends();
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
