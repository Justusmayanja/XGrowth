<?php

namespace App\Services\TikTok;

use App\Models\AccountProfile;
use App\Models\TikTokClip;
use App\Models\User;
use App\Support\TikTokCatalog;

class TikTokDemandService
{
    /**
     * Local idea bank. There is no TikTok search behind this list.
     * The same account state always returns the same order.
     *
     * @return list<array{kind: string, topic: string, angle: string, score: int, reason: string}>
     */
    public function ranked(User $user): array
    {
        $profile = $user->accountProfile;
        if (! $profile) {
            return [];
        }

        $niche = $this->niche($profile);
        $detail = $this->detail($profile);
        $weights = $this->weights($profile);
        $adjustment = $this->learningAdjustment($user);
        $recent = $this->recentTopics($user);

        $ideas = [];
        foreach ($this->bank($niche, $detail) as $idea) {
            $affinity = TikTokCatalog::kindAffinities()[$idea['kind']] ?? ['hook_speed' => 0, 'reason_to_stay' => 0, 'link_fit' => 0];
            $dot = ($affinity['hook_speed'] * $weights['hook_speed'])
                + ($affinity['reason_to_stay'] * $weights['reason_to_stay'])
                + ($affinity['link_fit'] * $weights['link_fit']);
            $score = (int) round($dot * 70) + ($adjustment[$idea['kind']] ?? 0);
            $idea['score'] = max(0, min(100, $score));
            $ideas[] = $idea;
        }

        $fresh = array_values(array_filter(
            $ideas,
            fn (array $idea) => ! in_array($idea['topic'], $recent, true),
        ));

        if ($fresh !== []) {
            $ideas = $fresh;
        } else {
            foreach ($ideas as &$idea) {
                $idea['score'] = max(0, $idea['score'] - TikTokCatalog::REPETITION_PENALTY);
            }
            unset($idea);
        }

        usort($ideas, function (array $a, array $b): int {
            return [$b['score'], $a['kind'], $a['topic']] <=> [$a['score'], $b['kind'], $b['topic']];
        });

        return $ideas;
    }

    private function niche(AccountProfile $profile): string
    {
        $niche = trim($profile->resolvedNiche());

        return $niche !== '' ? $niche : 'this subject';
    }

    /**
     * A word from the account description, so the angle is specific without changing the topic list.
     */
    private function detail(AccountProfile $profile): string
    {
        $stop = ['about', 'after', 'again', 'their', 'there', 'these', 'those', 'which', 'while', 'would', 'could', 'should', 'being', 'every', 'other'];
        $words = preg_split('/[^A-Za-z0-9]+/', strtolower((string) $profile->description)) ?: [];
        foreach ($words as $word) {
            if (strlen($word) >= 5 && ! in_array($word, $stop, true)) {
                return $word;
            }
        }

        return 'detail';
    }

    /**
     * @return array{hook_speed: float, reason_to_stay: float, link_fit: float}
     */
    private function weights(AccountProfile $profile): array
    {
        $strategies = TikTokCatalog::goalStrategies();
        $chosen = [];
        foreach ($profile->goalsFor('tiktok') as $goal) {
            if (isset($strategies[$goal]['weights'])) {
                $chosen[] = $strategies[$goal]['weights'];
            }
        }

        if ($chosen === []) {
            return ['hook_speed' => 0.5, 'reason_to_stay' => 0.5, 'link_fit' => 0.5];
        }

        $weights = ['hook_speed' => 0.0, 'reason_to_stay' => 0.0, 'link_fit' => 0.0];
        foreach ($chosen as $row) {
            foreach ($weights as $key => $value) {
                $weights[$key] = $value + (float) $row[$key];
            }
        }
        $count = count($chosen);
        foreach ($weights as $key => $value) {
            $weights[$key] = $value / $count;
        }

        return $weights;
    }

    /**
     * @return array<string, int>
     */
    private function learningAdjustment(User $user): array
    {
        $clips = TikTokClip::query()
            ->forUser($user)
            ->where('status', TikTokCatalog::LEARNED)
            ->latest('id')
            ->limit(10)
            ->get(['package', 'completion_percent']);

        $totals = [];
        foreach ($clips as $clip) {
            $kind = $clip->package['idea_kind'] ?? null;
            if (! is_string($kind) || $kind === '' || $clip->completion_percent === null) {
                continue;
            }
            $percent = (float) $clip->completion_percent;
            if ($percent >= TikTokCatalog::HELD_AT_OR_ABOVE) {
                $totals[$kind] = ($totals[$kind] ?? 0) + TikTokCatalog::LEARNING_STEP;
            } elseif ($percent < TikTokCatalog::LEFT_EARLY_BELOW) {
                $totals[$kind] = ($totals[$kind] ?? 0) - TikTokCatalog::LEARNING_STEP;
            }
        }

        foreach ($totals as $kind => $total) {
            $totals[$kind] = max(-TikTokCatalog::LEARNING_CAP, min(TikTokCatalog::LEARNING_CAP, $total));
        }

        return $totals;
    }

    /**
     * @return list<string>
     */
    private function recentTopics(User $user): array
    {
        return TikTokClip::query()
            ->forUser($user)
            ->where('status', '!=', TikTokCatalog::DISCARDED)
            ->latest('id')
            ->limit(10)
            ->pluck('topic')
            ->filter(fn ($topic) => is_string($topic) && $topic !== '')
            ->values()
            ->all();
    }

    /**
     * At least three topics per kind. Topics stay stable so the repetition guard can match them.
     *
     * @return list<array{kind: string, topic: string, angle: string, reason: string}>
     */
    private function bank(string $niche, string $detail): array
    {
        $rows = [
            TikTokCatalog::OBSERVATION => [
                'reason' => 'A quick pattern people recognize in the first second.',
                'topics' => [
                    "Something you notice about {$niche} every week",
                    "The quiet {$niche} detail people walk past",
                    "A {$niche} pattern hiding in plain sight",
                ],
            ],
            TikTokCatalog::STORY => [
                'reason' => 'A reason to stay, suits turning viewers into followers.',
                'topics' => [
                    "How a {$niche} plan fell apart, then worked",
                    "One {$niche} night that changed the routine",
                    "The {$niche} story that starts in the middle",
                ],
            ],
            TikTokCatalog::QUESTION => [
                'reason' => 'Opens on a question and pays it off before the end.',
                'topics' => [
                    "The {$niche} question people ask too late",
                    "What should you skip in {$niche}",
                    "Why does {$niche} feel harder than it is",
                ],
            ],
            TikTokCatalog::OPINION => [
                'reason' => 'Fast hook, suits getting onto For You.',
                'topics' => [
                    "Stop treating {$niche} like a personality",
                    "The {$niche} take most people get backwards",
                    "Unpopular {$niche} opinion: simpler is the point",
                ],
            ],
            TikTokCatalog::TIP => [
                'reason' => 'Can carry a link, suits sending people somewhere.',
                'topics' => [
                    "A {$niche} checklist for the next ten minutes",
                    "One {$niche} step you can finish today",
                    "The {$niche} shortcut that still does the job",
                ],
            ],
            TikTokCatalog::MYTH_BUST => [
                'reason' => 'Corrects a common line without a lecture.',
                'topics' => [
                    "The {$niche} myth that wastes a week",
                    "{$niche} does not need the expensive version",
                    "What people get wrong about {$niche}",
                ],
            ],
        ];

        $ideas = [];
        foreach ($rows as $kind => $row) {
            foreach ($row['topics'] as $topic) {
                $ideas[] = [
                    'kind' => $kind,
                    'topic' => $topic,
                    'angle' => "Stay inside {$niche}. Use the account's {$detail}.",
                    'reason' => $row['reason'],
                ];
            }
        }

        return $ideas;
    }
}
