<?php

namespace App\Services\TrendEngine;

use App\Models\AccountProfile;
use App\Models\Trend;

class OpportunityScorerService
{
    public function __construct(
        private readonly TrendEngineService $trendEngine,
    ) {}

    public function scoreForProfile(Trend $trend, AccountProfile $profile): float
    {
        $base = (float) $trend->score;
        $niche = $profile->niche ?? 'general';
        $tags = $trend->niche_tags ?? [];

        $score = $base;

        if (in_array($niche, $tags, true)) {
            $score += 12;
        } elseif ($this->nicheOverlap($niche, $tags)) {
            $score += 6;
        }

        $growth = (float) $trend->growth_rate;
        if ($growth >= 30) {
            $score += 8;
        } elseif ($growth >= 15) {
            $score += 4;
        }

        if ($trend->competition === 'low') {
            $score += 6;
        } elseif ($trend->competition === 'high') {
            $score -= 8;
        }

        if ($profile->mode === 'reactive' && $this->isEventLike($trend->name)) {
            $score += 5;
        }

        if ($profile->mode === 'proactive' && ! $this->isEventLike($trend->name)) {
            $score += 4;
        }

        return round(min(100, max(0, $score)), 1);
    }

    /**
     * @return array<int, array{trend: Trend, opportunity_score: float, suggestion: string}>
     */
    public function rankedForProfile(AccountProfile $profile, int $limit = 10): array
    {
        $niche = $profile->niche;

        $trends = Trend::query()
            ->when($niche, fn ($q) => $q->whereJsonContains('niche_tags', $niche))
            ->orderByDesc('score')
            ->limit(20)
            ->get();

        if ($trends->isEmpty()) {
            $trends = Trend::query()->orderByDesc('score')->limit(20)->get();
        }

        return $trends
            ->map(function (Trend $trend) use ($profile) {
                $opportunityScore = $this->scoreForProfile($trend, $profile);

                return [
                    'trend' => $trend,
                    'opportunity_score' => $opportunityScore,
                    'suggestion' => $this->suggestionForScore($opportunityScore, $trend->name),
                ];
            })
            ->sortByDesc('opportunity_score')
            ->take($limit)
            ->values()
            ->all();
    }

    private function suggestionForScore(float $score, string $trendName): string
    {
        if ($score >= 85) {
            return "Strong moment to post about \"{$trendName}\"";
        }
        if ($score >= 70) {
            return "Good time to draft content or engage on \"{$trendName}\"";
        }
        if ($score >= 55) {
            return "Watch \"{$trendName}\" — opportunity building";
        }

        return "Low priority — wait for a stronger moment";
    }

    private function nicheOverlap(string $niche, array $tags): bool
    {
        $related = [
            'football' => ['entertainment', 'memes'],
            'technology' => ['business'],
            'business' => ['technology', 'crypto'],
            'crypto' => ['business', 'technology'],
            'motivation' => ['entertainment', 'memes'],
            'memes' => ['entertainment', 'football'],
        ];

        foreach ($related[$niche] ?? [] as $relatedTag) {
            if (in_array($relatedTag, $tags, true)) {
                return true;
            }
        }

        return false;
    }

    private function isEventLike(string $name): bool
    {
        $keywords = ['breaking', 'news', 'deadline', 'match', 'launch', 'market', 'volatility', 'live'];

        $lower = strtolower($name);

        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
