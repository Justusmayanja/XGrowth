<?php

namespace App\Services\GrowthAgent;

use App\Models\AccountProfile;
use App\Models\Post;
use App\Models\Trend;
use App\Models\User;

/**
 * A pre-post score for “will this travel if we send it now?”
 * Uses the timeline already fetched, the draft text, and this account’s own results.
 * No extra API call.
 */
class ViralityPredictorService
{
    /**
     * @return array{
     *     score: float,
     *     worth_posting: bool,
     *     estimated_reach: string,
     *     reason: string,
     *     parts: array{moment: float, draft: float, history: float}
     * }
     */
    public function predict(
        User $user,
        string $content,
        ?Trend $trend,
        ?AccountProfile $profile,
        ?int $accountId = null,
        int $floor = 75,
        ?float $opportunity = null,
    ): array {
        $moment = $this->momentScore($trend, $opportunity);
        $draft = $this->draftScore($content, $trend, $profile);
        $history = $this->historyScore($user, $accountId);
        $score = round(min(100, max(0, ($moment * 0.55) + ($draft * 0.25) + ($history * 0.20))), 1);
        $spike = $this->isSpike($trend, $moment);
        $worth = $score >= $floor || ($spike && $score >= min($floor, 60));

        return [
            'score' => $score,
            'worth_posting' => $worth,
            'estimated_reach' => $score >= 80 ? 'high' : ($score >= 60 ? 'medium' : 'low'),
            'reason' => $this->reason($score, $moment, $draft, $history, $worth, $spike, $floor),
            'parts' => [
                'moment' => $moment,
                'draft' => $draft,
                'history' => $history,
            ],
        ];
    }

    public function momentScore(?Trend $trend, ?float $opportunity = null): float
    {
        if ($opportunity !== null) {
            return round(min(100, max(0, $opportunity)), 1);
        }

        if ($trend === null) {
            return 35.0;
        }

        $base = (float) $trend->score;
        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        $minutes = isset($meta['minutes_ago']) && is_numeric($meta['minutes_ago'])
            ? (int) $meta['minutes_ago']
            : null;
        $followers = (int) ($meta['author_followers'] ?? 0);

        if ($minutes !== null && $minutes > 12 * 60) {
            $base = min($base, 40);
        } elseif ($minutes !== null && $minutes <= 25 && $followers >= 100000) {
            $base = max($base, 94);
        } elseif ($minutes !== null && $minutes <= 40 && $followers >= 25000) {
            $base = max($base, 90);
        } elseif ($minutes !== null && $minutes > 180) {
            $base = min($base, 64);
        }

        return round(min(100, max(0, $base)), 1);
    }

    public function draftScore(string $content, ?Trend $trend, ?AccountProfile $profile): float
    {
        $content = trim($content);
        if ($content === '') {
            return 0.0;
        }

        $score = 50.0;
        $words = $this->words($content);
        $count = count($words);

        if ($count >= 6 && $count <= 40) {
            $score += 10;
        } elseif ($count < 4 || $count > 55) {
            $score -= 12;
        }

        if (preg_match('/\d|@/', $content) === 1) {
            $score += 8;
        }
        if (str_contains($content, '?')) {
            $score += 6;
        }

        $source = $this->sourceText($trend);
        $overlap = $this->sharedCount($words, $this->words($source));
        if ($overlap >= 1) {
            $score += 8;
        }
        if ($overlap >= 2) {
            $score += 4;
        }

        if ($this->looksGeneric($content)) {
            $score -= 16;
        }

        if ($profile) {
            $bio = trim((string) $profile->description.' '.(string) $profile->audience);
            $bioOverlap = $this->sharedCount($words, $this->words($bio));
            if ($bio !== '' && $count > 0 && ($bioOverlap / $count) >= 0.35) {
                $score -= 22;
            }
        }

        return round(min(100, max(0, $score)), 1);
    }

    public function historyScore(User $user, ?int $accountId = null): float
    {
        $query = Post::query()
            ->where('user_id', $user->id)
            ->where('status', 'posted')
            ->where('content_type', 'tweet')
            ->latest('posted_at')
            ->limit(8);

        if ($accountId) {
            $query->where('connected_account_id', $accountId);
        }

        $posts = $query->get();
        if ($posts->count() < 2) {
            return 55.0;
        }

        $recent = $posts->take(3);
        $known = $recent->filter(function (Post $post) {
            return (int) $post->impressions > 0
                || ($post->posted_at && $post->posted_at->lt(now()->subHours(6)));
        });

        if ($known->count() >= 3 && $known->every(fn (Post $post) => (int) $post->replies <= 0)) {
            return 28.0;
        }

        $replies = (int) $posts->sum('replies');
        $impressions = (int) $posts->sum('impressions');
        $engagements = $replies + (int) $posts->sum('likes') + (int) $posts->sum('reposts');

        if ($posts->max('replies') >= 5) {
            return 82.0;
        }

        if ($impressions > 0 && ($engagements / $impressions) >= 0.02) {
            return 76.0;
        }

        if ($replies === 0) {
            return 38.0;
        }

        return 58.0;
    }

    public function isSpike(?Trend $trend, float $moment): bool
    {
        if ($moment >= 90) {
            return true;
        }

        $meta = is_array($trend?->metadata) ? $trend->metadata : [];
        $minutes = isset($meta['minutes_ago']) && is_numeric($meta['minutes_ago'])
            ? (int) $meta['minutes_ago']
            : null;
        $followers = (int) ($meta['author_followers'] ?? 0);

        return $minutes !== null && $minutes <= 40 && $followers >= 25000;
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $text = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s@]/u', ' ', $text) ?? $text);
        $stop = ['the', 'and', 'for', 'that', 'this', 'with', 'from', 'have', 'just', 'about', 'your', 'you', 'are', 'was', 'not', 'but', 'they', 'their'];
        $words = [];
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if (mb_strlen($word) < 3 || in_array($word, $stop, true)) {
                continue;
            }
            $words[] = $word;
        }

        return $words;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function sharedCount(array $left, array $right): int
    {
        if ($left === [] || $right === []) {
            return 0;
        }

        return count(array_intersect(array_unique($left), array_unique($right)));
    }

    private function sourceText(?Trend $trend): string
    {
        if ($trend === null) {
            return '';
        }

        $meta = is_array($trend->metadata) ? $trend->metadata : [];

        return trim((string) ($meta['text'] ?? '').' '.$trend->name.' '.(string) ($meta['author'] ?? ''));
    }

    private function looksGeneric(string $content): bool
    {
        $lower = mb_strtolower($content);

        foreach ([
            "here's the thing",
            'here is the thing',
            'thoughts?',
            'what do you think',
            'let that sink in',
            'as a ',
            "it's not a",
        ] as $cue) {
            if (str_contains($lower, $cue)) {
                return true;
            }
        }

        return false;
    }

    private function reason(
        float $score,
        float $moment,
        float $draft,
        float $history,
        bool $worth,
        bool $spike,
        int $floor,
    ): string {
        if ($worth && $spike) {
            return "A live moment ({$moment}/100). This draft is worth sending now ({$score}/100).";
        }

        if ($worth) {
            return "Worth posting. The room is {$moment}/100 and this take sits at {$score}/100.";
        }

        if ($moment < 60) {
            return "Held. The room is quiet ({$moment}/100). Wait for a louder beat.";
        }

        if ($draft < 45) {
            return "Held. The draft reads like a bio, not a take on this moment.";
        }

        if ($history < 35) {
            return "Held. The last posts on this account got no replies, so this needs a hotter moment.";
        }

        return "Held. Prediction {$score}/100, needs {$floor} before it goes live.";
    }
}
