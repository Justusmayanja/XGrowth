<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;

/**
 * The season comes from this channel's premise and voice.
 * The same six beats are not reused for every niche.
 */
class YouTubeSeriesPlanner
{
    public function __construct(private readonly YouTubeWords $words) {}

    /**
     * @return array{title: string, premise: string, characters: list<array{name: string, role: string}>, outline: list<array{number: int, title: string, beat: string}>}
     */
    public function plan(AccountProfile $profile): array
    {
        $premise = trim((string) $profile->description);
        if ($premise === '') {
            $premise = $profile->resolvedNiche();
        }
        $audience = trim((string) $profile->audience) ?: 'the people this account is for';
        $tone = $profile->primaryTone() ?: 'plain';
        $outline = $this->outline($premise, $audience, $tone);

        return [
            'title' => $this->words->cut($premise, 120),
            'premise' => $premise,
            'characters' => [
                ['name' => 'The channel', 'role' => 'Speaks in a '.$tone.' voice and does not leave this description: '.$premise],
                ['name' => $this->words->cut($audience, 80), 'role' => 'The person this account is already for.'],
            ],
            'outline' => $outline,
        ];
    }

    /**
     * @return list<array{number: int, title: string, beat: string}>
     */
    public function outline(string $premise, string $audience, string $tone): array
    {
        $clauses = $this->clauses($premise);
        $first = $clauses[0];
        $second = $clauses[1] ?? $first;
        $who = $audience !== '' ? $audience : 'the viewer';

        $rows = [
            [$first, "Open on {$first}. Say it in a {$tone} voice to {$who}. Stay inside the premise."],
            [$second, "Show {$second} as the change from the first episode. {$who} is still the viewer."],
            ["{$who} tries {$first}", "{$who} copies one step that is already in the premise. Do not add a new subject."],
            ["The limit of {$second}", "Name the limit already implied by {$second}. The niche does not change."],
            ["{$first} on another night", "Repeat {$first} once, with the same means the premise already uses."],
            ["After {$second}", "{$first} is done. The next problem is still about {$second}."],
        ];

        $outline = [];
        $seen = [];
        foreach ($rows as $index => [$title, $beat]) {
            $clean = $this->episodeTitle($title);
            if (isset($seen[strtolower($clean)])) {
                $clean = $this->episodeTitle($clean.' for '.$who);
            }
            $seen[strtolower($clean)] = true;
            $outline[] = [
                'number' => $index + 1,
                'title' => $clean,
                'beat' => $beat,
            ];
        }

        return $outline;
    }

    /** @return list<string> */
    private function clauses(string $premise): array
    {
        $parts = preg_split('/\s+and\s+|,\s+/i', trim($premise)) ?: [];
        $clauses = [];
        foreach ($parts as $part) {
            $part = trim($part, " .");
            $part = preg_replace('/^I\s+/i', '', $part) ?? $part;
            if ($part !== '') {
                $clauses[] = $part;
            }
        }

        return $clauses !== [] ? $clauses : [trim($premise)];
    }

    private function episodeTitle(string $title): string
    {
        $title = trim(preg_replace('/^episode\s+\d+\s*[:\-]?\s*/i', '', $title) ?? $title);

        return $this->words->cut($title, 80);
    }
}
