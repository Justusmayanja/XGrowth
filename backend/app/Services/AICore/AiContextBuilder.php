<?php

namespace App\Services\AICore;

use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\Post;
use App\Models\Trend;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;

/**
 * Builds minimal structured context for AI calls — short keys, no prose dumps.
 */
class AiContextBuilder
{
    public function __construct(
        private readonly PlatformSettingsService $settings,
    ) {}
    /**
     * @return array<string, mixed>
     */
    public function profileDigest(AccountProfile $profile): array
    {
        return [
            'niche' => $profile->resolvedNiche(),
            'tones' => $profile->tones() ?: ['professional'],
            'goal' => $profile->primaryGoal(),
            'mode' => $profile->mode ?? 'reactive',
            'automation' => (int) ($profile->automation_level ?? 1),
            'auto_post' => (bool) ($profile->auto_post_enabled ?? false),
            'auto_engage' => (bool) ($profile->auto_engage_enabled ?? true),
        ];
    }

    /**
     * @param  list<Trend>  $trends
     * @return list<array<string, mixed>>
     */
    public function trendDigest(array $trends): array
    {
        return array_map(function (Trend $trend) {
            $meta = $trend->metadata ?? [];

            return [
                'id' => $trend->id,
                'n' => mb_substr($trend->name, 0, 80),
                'score' => (float) $trend->score,
                'growth' => (float) $trend->growth_rate,
                'comp' => $trend->competition,
                'tags' => array_slice($trend->niche_tags ?? [], 0, 4),
                'src' => $meta['source'] ?? 'catalog',
                'x_snip' => isset($meta['x_summary']) ? mb_substr((string) $meta['x_summary'], 0, 120) : null,
            ];
        }, $trends);
    }

    /**
     * @param  array<string, array{count: int, daily_limit: int}>  $safetyActions
     * @return array<string, int>
     */
    public function safetyDigest(array $safetyActions): array
    {
        $digest = [];

        foreach (['post', 'reply', 'follow', 'like'] as $type) {
            $row = $safetyActions[$type] ?? ['count' => 0, 'daily_limit' => 0];
            $digest["{$type}_left"] = max(0, $row['daily_limit'] - $row['count']);
        }

        return $digest;
    }

    /**
     * @return array<string, mixed>
     */
    public function contentMixDigest(User $user): array
    {
        $maxTrendPct = $this->settings->trendContentMaxPercent();
        $window = max(1, $this->settings->getInt('content_mix_sample_size', 10));

        $posts = Post::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'scheduled', 'posted'])
            ->latest()
            ->limit($window)
            ->get(['trend_id']);

        $total = $posts->count();
        $trendBased = $posts->whereNotNull('trend_id')->count();
        $recentTrendPct = $total > 0 ? (int) round(($trendBased / $total) * 100) : 0;

        $prefer = 'balanced';
        if ($recentTrendPct >= $maxTrendPct) {
            $prefer = 'original';
        } elseif ($recentTrendPct < max(0, $maxTrendPct - 25)) {
            $prefer = 'trend';
        }

        return [
            'max_trend_pct' => $maxTrendPct,
            'min_original_pct' => 100 - $maxTrendPct,
            'recent_trend_pct' => $recentTrendPct,
            'sample_size' => $total,
            'prefer' => $prefer,
        ];
    }

    /**
     * Ways to take a side. The account's meaning lives on the profile, not in these lines.
     *
     * @return list<string>
     */
    public function originalIdeasDigest(AccountProfile $profile): array
    {
        return [
            'Answer a conversation that is about someone else, from this side',
            'One opinion hung on a name, price, score, or decision people already know',
            'A short reply to praise or panic, without restating who the account is',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastDecisionDigest(User $user): ?array
    {
        $last = AiDecision::query()
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        if (! $last) {
            return null;
        }

        $context = is_array($last->context) ? $last->context : [];
        $trendName = $context['trend_name'] ?? null;

        return [
            'd' => $last->decision,
            'score' => $last->opportunity_score !== null ? (float) $last->opportunity_score : null,
            'mins' => $last->created_at?->diffInMinutes(now()),
            'trend' => $trendName ? mb_substr((string) $trendName, 0, 60) : null,
            'src' => $context['content_source'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '{}';
    }
}
