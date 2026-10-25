<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;
use App\Models\User;
use App\Models\YouTubeVideo;

class YouTubeDemandService
{
    /**
     * Ideas already moving inside this niche. The list is local until YouTube search is connected.
     * One list is shared by every account in the niche for the day, via the caller.
     *
     * @return list<array{topic: string, query: string, kind: string, reason: string, score: float}>
     */
    public function ranked(AccountProfile $profile, string $format, User $user, int $limit = 6): array
    {
        $niche = $this->niche($profile);
        $audience = $this->audience($profile);
        $recent = YouTubeVideo::query()
            ->where('user_id', $user->id)
            ->where('mode', 'short')
            ->latest('id')
            ->limit(8)
            ->pluck('topic')
            ->filter()
            ->all();

        $learned = YouTubeVideo::query()
            ->where('user_id', $user->id)
            ->where('mode', 'short')
            ->whereNotNull('learned_at')
            ->latest('id')
            ->limit(8)
            ->get(['package', 'average_view_percent']);

        $strong = [];
        $weak = [];
        foreach ($learned as $video) {
            $kind = $video->package['kind'] ?? null;
            if (! is_string($kind) || $kind === '') {
                continue;
            }
            $percent = $video->average_view_percent;
            if ($percent !== null && $percent >= 50) {
                $strong[] = $kind;
            } elseif ($percent !== null && $percent < 30) {
                $weak[] = $kind;
            }
        }

        $ideas = $this->ideas($niche, $audience);
        $fresh = array_values(array_filter($ideas, fn (array $idea) => ! in_array($idea['topic'], $recent, true)));
        if ($fresh !== []) {
            $ideas = $fresh;
        }

        $goals = $profile->goalsFor('youtube');

        foreach ($ideas as &$idea) {
            $score = 50.0;
            if ($format === 'list_facts' && $idea['kind'] === 'list') {
                $score += 18;
            }
            if ($format === 'text_on_screen' && in_array($idea['kind'], ['mistake', 'myth'], true)) {
                $score += 8;
            }
            if ($format === 'scene' && $idea['kind'] === 'result') {
                $score += 8;
            }
            if ($format === 'faceless_voiceover' && $idea['kind'] === 'question') {
                $score += 8;
            }
            if (in_array('search', $goals, true)) {
                $score += 10;
            }
            if (in_array($idea['kind'], $strong, true)) {
                $score += 20;
            }
            if (in_array($idea['kind'], $weak, true)) {
                $score -= 30;
            }
            $idea['score'] = max(0, min(100, $score));
        }
        unset($idea);

        usort($ideas, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($ideas, 0, $limit);
    }

    private function niche(AccountProfile $profile): string
    {
        $niche = trim($profile->resolvedNiche());

        return $niche !== '' ? $niche : 'this subject';
    }

    private function audience(AccountProfile $profile): string
    {
        $audience = trim((string) $profile->audience);

        return $audience !== '' ? $audience : 'the people this account is for';
    }

    /**
     * @return list<array{topic: string, query: string, kind: string, reason: string, score: float}>
     */
    private function ideas(string $niche, string $audience): array
    {
        return [
            [
                'topic' => "The {$niche} mistake that keeps getting repeated",
                'query' => "{$niche} mistake",
                'kind' => 'mistake',
                'reason' => "People already argue about this mistake in {$niche}.",
                'score' => 0,
            ],
            [
                'topic' => "A {$niche} result in one sitting",
                'query' => "{$niche} for beginners",
                'kind' => 'result',
                'reason' => "A finished result is what gets saved and rewatched in {$niche}.",
                'score' => 0,
            ],
            [
                'topic' => "What people get wrong about {$niche}",
                'query' => "{$niche} explained",
                'kind' => 'myth',
                'reason' => "This is the kind of correction {$audience} stops for.",
                'score' => 0,
            ],
            [
                'topic' => "Three {$niche} steps that finish the job",
                'query' => "{$niche} steps",
                'kind' => 'list',
                'reason' => "A short list matches how people search for {$niche}.",
                'score' => 0,
            ],
            [
                'topic' => "The {$niche} question people actually ask",
                'query' => "{$niche} question",
                'kind' => 'question',
                'reason' => "It is the question {$audience} would type.",
                'score' => 0,
            ],
            [
                'topic' => "Start {$niche} without spending much",
                'query' => "{$niche} on a budget",
                'kind' => 'budget',
                'reason' => "Budget versions of {$niche} keep getting watched.",
                'score' => 0,
            ],
        ];
    }
}
