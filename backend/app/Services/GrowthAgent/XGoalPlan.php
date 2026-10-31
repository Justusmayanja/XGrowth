<?php

namespace App\Services\GrowthAgent;

use App\Models\AccountProfile;
use App\Models\Post;
use App\Models\Trend;
use App\Services\ApiSpend\ApiSpendService;

/**
 * Turns the X goals into a choice: which moment, a post or a reply, and whether a link is allowed.
 * The first goal wins when two goals ask for different moves.
 */
class XGoalPlan
{
    public function __construct(private readonly ApiSpendService $budget) {}

    /** @return list<string> */
    public function goals(AccountProfile $profile): array
    {
        $goals = $profile->goalsFor('x');

        return $goals !== [] ? $goals : [$profile->primaryGoal()];
    }

    /** @param  list<string>  $goals */
    public function prefersReply(array $goals): bool
    {
        return array_intersect($goals, ['monetize', 'influencer']) !== [];
    }

    public function prefersReplyFor(AccountProfile $profile): bool
    {
        return $this->prefersReply($this->goals($profile));
    }

    public function hasAudience(Trend $trend): bool
    {
        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        $id = trim((string) ($meta['tweet_id'] ?? ''));

        return $id !== '' && (int) ($meta['author_followers'] ?? 0) >= 10000;
    }

    public function skipsMoment(AccountProfile $profile, Trend $trend): bool
    {
        $goals = $this->goals($profile);
        if (($goals[0] ?? '') !== 'promote_business') {
            return false;
        }

        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        $text = trim((string) ($meta['text'] ?? $trend->name));

        return ! $this->touches($text, (string) $profile->description);
    }

    /**
     * @param  list<string>  $goals
     * @param  list<array{trend: Trend, opportunity_score: float, suggestion?: string}>  $ranked
     * @return list<array{trend: Trend, opportunity_score: float, suggestion?: string}>
     */
    public function orderMoments(array $goals, array $ranked): array
    {
        if (array_intersect($goals, ['monetize', 'influencer', 'build_brand']) === []) {
            return $ranked;
        }

        foreach ($ranked as $index => $row) {
            $ranked[$index]['opportunity_score'] = $this->adjustedScore($goals, $row);
        }

        usort($ranked, fn (array $a, array $b) => $b['opportunity_score'] <=> $a['opportunity_score']);

        return $ranked;
    }

    /**
     * @param  list<string>  $goals
     * @param  list<array{reach?: int, replies?: int}>  $candidates
     * @return list<array{reach?: int, replies?: int}>
     */
    public function orderCandidates(array $goals, array $candidates): array
    {
        if (! $this->prefersReply($goals)) {
            return $candidates;
        }

        usort($candidates, function (array $a, array $b): int {
            return [($b['reach'] ?? 0), ($b['replies'] ?? 0)] <=> [($a['reach'] ?? 0), ($a['replies'] ?? 0)];
        });

        return $candidates;
    }

    /** @return list<string> */
    public function replyPhrases(AccountProfile $profile): array
    {
        if (($this->goals($profile)[0] ?? '') === 'promote_business') {
            return array_values(array_filter([trim((string) $profile->description)]));
        }

        return array_values(array_filter([
            $profile->resolvedNiche(),
            trim((string) $profile->description),
            trim((string) $profile->audience),
        ]));
    }

    public function postBrief(AccountProfile $profile): string
    {
        $goals = $this->goals($profile);
        $url = $this->destination((string) $profile->description.' '.(string) $profile->audience);
        $lines = [];
        foreach ($goals as $goal) {
            $lines[] = $this->postLine($goal, $url);
        }

        return implode(' ', $lines);
    }

    public function replyBrief(AccountProfile $profile): string
    {
        $goals = $this->goals($profile);
        $url = $this->destination((string) $profile->description.' '.(string) $profile->audience);
        $lines = [];
        foreach ($goals as $goal) {
            $lines[] = $this->replyLine($goal, $url);
        }

        return implode(' ', $lines);
    }

    public function allowsLink(AccountProfile $profile): bool
    {
        return in_array('drive_traffic', $this->goals($profile), true);
    }

    public function finishText(AccountProfile $profile, string $text): string
    {
        $notes = (string) $profile->description.' '.(string) $profile->audience;
        if (! $this->allowsLink($profile)) {
            return $this->budget->withoutLinks($text);
        }

        $url = $this->destination($notes);
        $clean = $this->budget->withoutLinks($text);
        if ($url === null) {
            return $clean;
        }

        if (str_contains($clean, $url)) {
            return $clean;
        }

        $with = trim($clean.' '.$url);

        return mb_strlen($with) <= 270 ? $with : $clean;
    }

    /**
     * @return array{goals: list<string>, metric: string, lesson: string, recent: list<array{kind: string, impressions: int, replies: int, content: string}>}
     */
    public function results(AccountProfile $profile): array
    {
        $goals = $this->goals($profile);
        $recent = $this->recentRows($profile);

        return [
            'goals' => $goals,
            'metric' => $this->metric($goals[0] ?? 'grow_followers'),
            'lesson' => $this->lesson($goals, $recent),
            'recent' => array_slice($recent, 0, 5),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $live
     * @return array{goal: string, label: string, value: int|null, sub: string, lesson: string}
     */
    public function progress(AccountProfile $profile, ?array $live): array
    {
        $goals = $this->goals($profile);
        $primary = $goals[0] ?? 'grow_followers';
        $figure = $this->figure($primary, $live);

        return [
            'goal' => $primary,
            'label' => $figure['label'],
            'value' => $figure['value'],
            'sub' => $figure['sub'],
            'lesson' => $this->lesson($goals, $this->recentRows($profile)),
        ];
    }

    /**
     * @param  list<string>  $goals
     * @param  list<array{kind?: string, impressions?: int, replies?: int, content?: string}>  $rows
     */
    public function lesson(array $goals, array $rows): string
    {
        $primary = $goals[0] ?? 'grow_followers';
        $best = null;
        $bestScore = 0;
        foreach ($rows as $row) {
            $score = $this->rowScore($primary, $row);
            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return $this->opening($primary);
        }

        $kind = ($best['kind'] ?? 'tweet') === 'reply' ? 'reply' : 'post';
        $snippet = trim((string) ($best['content'] ?? ''));
        $tail = $snippet !== '' ? ' It started: '.$snippet : '';

        return match ($primary) {
            'monetize' => "Impressions came from a {$kind}. Do that kind again.{$tail}",
            'grow_followers' => "Replies came from a {$kind}. Do that kind again.{$tail}",
            'promote_business', 'drive_traffic' => "The {$kind} is what got attention. Stay on the offer.{$tail}",
            default => "The {$kind} is what people answered. Stay on that subject.{$tail}",
        };
    }

    /**
     * @param  list<string>  $goals
     * @param  array{trend: Trend, opportunity_score: float}  $row
     */
    private function adjustedScore(array $goals, array $row): float
    {
        $score = (float) $row['opportunity_score'];
        $meta = is_array($row['trend']->metadata) ? $row['trend']->metadata : [];
        $reach = (int) ($meta['author_followers'] ?? 0);

        if (array_intersect($goals, ['monetize', 'influencer']) !== []) {
            if ($reach >= 100000) {
                $score += 10;
            } elseif ($reach >= 10000) {
                $score += 6;
            }
        }

        if (array_intersect($goals, ['build_brand', 'influencer']) !== [] && ! empty($meta['adjacent'])) {
            $score -= 25;
        }

        return max(0, min(100, $score));
    }

    private function postLine(string $goal, ?string $url): string
    {
        return match ($goal) {
            'monetize' => 'Write a take people already in this conversation would reply to or share. No link and no pitch. Impressions are the point.',
            'grow_followers' => 'Write a take worth opening the profile for. No link and no pitch.',
            'build_brand' => 'Stay on the subjects this account is known for, in the same voice. No link.',
            'drive_traffic' => $url
                ? 'Name the destination in words. The app adds the saved link. Do not invent a URL.'
                : 'Name the destination from the description. Do not invent a URL.',
            'promote_business' => 'Name what the business sells, because this moment is already about that. No link unless sending people somewhere is also a goal.',
            'influencer' => 'Answer as someone people in this niche would follow. No link and no pitch.',
            default => 'Stay on the niche and the people this account is for.',
        };
    }

    private function replyLine(string $goal, ?string $url): string
    {
        return match ($goal) {
            'monetize' => 'One or two sentences a person would reply. No pitch and no link. Be seen in a conversation that already has an audience.',
            'grow_followers' => 'One or two sentences that give a reason to open the profile. No pitch and no link.',
            'build_brand' => 'Answer in this account\'s voice and stay on the subject. No pitch and no link.',
            'drive_traffic' => $url
                ? "Point at {$url}. Do not invent a different URL."
                : 'Point at the destination named in the description. Do not invent a URL.',
            'promote_business' => 'Name what the business sells if this post is already about that. No link unless sending people somewhere is also a goal.',
            'influencer' => 'Answer the larger account in this voice. No pitch and no link.',
            default => 'One or two sentences in this voice. No pitch and no link.',
        };
    }

    private function opening(string $goal): string
    {
        return match ($goal) {
            'monetize' => 'No results yet. Reply under a post people are already reading. Impressions are what a creator payout counts.',
            'grow_followers' => 'No results yet. Post a take worth opening the profile for, then reply where those people already are.',
            'build_brand' => 'No results yet. Stay on the same subjects and the same voice.',
            'drive_traffic' => 'No results yet. Point at the destination named in the description.',
            'promote_business' => 'No results yet. Answer only when the conversation is already about what the business sells.',
            'influencer' => 'No results yet. Reply under larger accounts in this niche.',
            default => 'No results yet. Stay on the niche and the people this account is for.',
        };
    }

    private function metric(string $goal): string
    {
        return match ($goal) {
            'monetize' => 'impressions',
            'grow_followers' => 'followers',
            'promote_business', 'drive_traffic' => 'profile_visits',
            default => 'replies',
        };
    }

    /**
     * @param  array<string, mixed>|null  $live
     * @return array{label: string, value: int|null, sub: string}
     */
    private function figure(string $goal, ?array $live): array
    {
        if ($live === null) {
            return [
                'label' => $this->label($goal),
                'value' => null,
                'sub' => 'Connect X to see whether this is moving.',
            ];
        }

        if ($goal === 'monetize' && empty($live['views_known'])) {
            return [
                'label' => 'Impressions',
                'value' => null,
                'sub' => 'X has not sent a view count yet.',
            ];
        }

        $value = match ($goal) {
            'monetize' => (int) ($live['impressions'] ?? 0),
            'grow_followers' => (int) ($live['followers'] ?? 0),
            'promote_business', 'drive_traffic' => (int) ($live['profile_clicks'] ?? 0),
            default => (int) ($live['replies'] ?? 0),
        };
        $change = match ($goal) {
            'monetize' => $live['impressions_change'] ?? null,
            'grow_followers' => $live['followers_change'] ?? null,
            default => null,
        };

        return [
            'label' => $this->label($goal),
            'value' => $value,
            'sub' => $this->pace($change),
        ];
    }

    private function label(string $goal): string
    {
        return match ($goal) {
            'monetize' => 'Impressions',
            'grow_followers' => 'Followers',
            'promote_business', 'drive_traffic' => 'Profile visits',
            default => 'Replies',
        };
    }

    private function pace(mixed $change): string
    {
        if (! is_numeric($change)) {
            return 'From the latest read';
        }

        $change = (int) $change;
        if ($change > 0) {
            return '+'.number_format($change).' since yesterday';
        }
        if ($change < 0) {
            return number_format($change).' since yesterday';
        }

        return 'No change since yesterday';
    }

    /**
     * @param  array{kind?: string, impressions?: int, replies?: int}  $row
     */
    private function rowScore(string $goal, array $row): int
    {
        $impressions = (int) ($row['impressions'] ?? 0);
        $replies = (int) ($row['replies'] ?? 0);

        return match ($goal) {
            'monetize' => $impressions,
            'grow_followers', 'build_brand', 'influencer' => $replies,
            default => max($replies, $impressions),
        };
    }

    /** @return list<array{kind: string, impressions: int, replies: int, content: string}> */
    private function recentRows(AccountProfile $profile): array
    {
        if (! $profile->user_id) {
            return [];
        }

        return Post::query()
            ->where('user_id', $profile->user_id)
            ->where('status', 'posted')
            ->whereIn('content_type', ['tweet', 'reply', 'quote'])
            ->latest('posted_at')
            ->limit(8)
            ->get(['content_type', 'content', 'impressions', 'replies'])
            ->map(fn (Post $post) => [
                'kind' => (string) $post->content_type,
                'impressions' => (int) $post->impressions,
                'replies' => (int) $post->replies,
                'content' => mb_substr((string) $post->content, 0, 80),
            ])
            ->all();
    }

    public function touches(string $text, string $description): bool
    {
        $stop = ['about', 'after', 'again', 'their', 'there', 'these', 'those', 'which', 'while', 'would', 'could', 'should', 'being', 'every', 'other', 'with', 'from', 'this', 'that', 'have', 'your'];
        $words = preg_split('/[^A-Za-z0-9]+/', mb_strtolower($description)) ?: [];
        $needles = [];
        foreach ($words as $word) {
            if (strlen($word) >= 5 && ! in_array($word, $stop, true)) {
                $needles[] = $word;
            }
        }

        if ($needles === []) {
            return true;
        }

        $haystack = mb_strtolower($text);
        foreach ($needles as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }

    private function destination(string $notes): ?string
    {
        if (preg_match('/https?:\/\/\S+|www\.\S+/i', $notes, $match) !== 1) {
            return null;
        }

        return rtrim($match[0], '.,)');
    }
}
