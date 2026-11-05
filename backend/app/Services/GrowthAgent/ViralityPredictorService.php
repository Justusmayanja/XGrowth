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
     *     pick: string,
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
            'estimated_reach' => $this->reach($score),
            'reason' => $this->reason($score, $moment, $draft, $history, $worth, $spike, $floor),
            'pick' => $worth ? 'post' : 'hold',
            'parts' => [
                'moment' => $moment,
                'draft' => $draft,
                'history' => $history,
            ],
        ];
    }

    /**
     * Three drafts share the same room and the same account. Rank the takes
     * against each other so one is posted and the others are left.
     *
     * @param  list<array{content: string, score: float, worth_posting: bool, estimated_reach: string, reason: string, parts: array{moment: float, draft: float, history: float}}>  $items
     * @return list<array{content: string, score: float, worth_posting: bool, estimated_reach: string, reason: string, pick: string, parts: array{moment: float, draft: float, history: float}}>
     */
    public function pickOne(array $items, int $floor = 75): array
    {
        if ($items === []) {
            return [];
        }

        if (count($items) === 1) {
            $items[0]['pick'] = ! empty($items[0]['worth_posting']) ? 'post' : 'hold';
            $items[0]['estimated_reach'] = $this->reach((float) $items[0]['score']);

            return $items;
        }

        $contents = array_map(fn (array $item) => (string) ($item['content'] ?? ''), $items);

        foreach ($items as $index => $item) {
            $draft = (float) ($item['parts']['draft'] ?? 50);
            $moment = (float) ($item['parts']['moment'] ?? 50);
            $history = (float) ($item['parts']['history'] ?? 55);
            $boost = $this->batchBoost((string) ($item['content'] ?? ''), $contents);
            $draft = min(100, max(0, $draft + $boost));
            $score = round(min(100, max(0, ($moment * 0.40) + ($draft * 0.45) + ($history * 0.15))), 1);
            $items[$index]['parts']['draft'] = $draft;
            $items[$index]['score'] = $score;
            $items[$index]['boost'] = $boost;
        }

        usort($items, function (array $left, array $right) {
            $byScore = $right['score'] <=> $left['score'];
            if ($byScore !== 0) {
                return $byScore;
            }

            return ((float) ($right['boost'] ?? 0)) <=> ((float) ($left['boost'] ?? 0));
        });

        $best = (float) $items[0]['score'];
        $seen = [];
        foreach ($items as $rank => $item) {
            $score = $rank === 0
                ? $best
                : min((float) $item['score'], $best - (5 * $rank));
            $int = (int) round(max(0, $score));
            while (in_array($int, $seen, true)) {
                $int = $int > 0 ? $int - 1 : max($seen) + 1;
            }
            $seen[] = $int;
            $items[$rank]['score'] = (float) $int;
            $items[$rank]['estimated_reach'] = $this->reach((float) $int);
        }

        $winner = $items[0];
        $winnerScore = (float) $winner['score'];
        $spike = $winnerScore >= 90;
        $winnerGoes = $winnerScore >= $floor || ($spike && $winnerScore >= min($floor, 60));

        foreach ($items as $rank => $item) {
            $score = (float) $item['score'];
            if ($rank === 0 && $winnerGoes) {
                $items[$rank]['worth_posting'] = true;
                $items[$rank]['pick'] = 'post';
                $items[$rank]['reason'] = 'Post this one. Strongest take of the set ('.((int) round($score)).'/100).';
            } elseif ($rank === 0) {
                $items[$rank]['worth_posting'] = false;
                $items[$rank]['pick'] = 'hold';
                $items[$rank]['reason'] = 'Best of these, still hold. '.((int) round($score))."/100, needs {$floor}.";
            } else {
                $items[$rank]['worth_posting'] = false;
                $items[$rank]['pick'] = 'leave';
                $items[$rank]['reason'] = 'Leave this one. A stronger take scored '.((int) round($winnerScore)).'/100.';
            }
        }

        return $items;
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

        if ($count >= 8 && $count <= 28) {
            $score += 12;
        } elseif ($count >= 6 && $count <= 40) {
            $score += 6;
        } elseif ($count < 4 || $count > 55) {
            $score -= 12;
        }

        if (preg_match('/\d/', $content) === 1) {
            $score += 8;
        }
        if (str_contains($content, '@')) {
            $score += 5;
        }
        if (str_contains($content, '?') && ! str_contains(mb_strtolower($content), 'thoughts?')) {
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

    /**
     * Extra points for a take that is not a copy of its siblings.
     *
     * @param  list<string>  $siblings
     */
    private function batchBoost(string $content, array $siblings): float
    {
        $mine = $this->words($content);
        if ($mine === []) {
            return -20.0;
        }

        $others = [];
        $openings = [];
        foreach ($siblings as $sibling) {
            if ($sibling === $content) {
                continue;
            }
            $others = array_merge($others, $this->words($sibling));
            $openings[] = $this->opening($sibling);
        }

        $unique = array_diff($mine, array_unique($others));
        $boost = (count($unique) / max(1, count($mine))) * 18;

        $opening = $this->opening($content);
        if ($opening !== '' && in_array($opening, $openings, true)) {
            $boost -= 12;
        } elseif ($opening !== '') {
            $boost += 5;
        }

        if ($this->looksGeneric($content)) {
            $boost -= 10;
        }

        return round($boost, 1);
    }

    private function opening(string $content): string
    {
        return implode(' ', array_slice($this->words($content), 0, 3));
    }

    private function reach(float $score): string
    {
        return $score >= 80 ? 'high' : ($score >= 60 ? 'medium' : 'low');
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
