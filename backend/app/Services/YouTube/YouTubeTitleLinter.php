<?php

namespace App\Services\YouTube;

/**
 * Title and thumbnail are one unit. Desktop search cuts near 60 characters,
 * a phone feed near 40. A name, number, or date outranks an adjective.
 * The thumbnail uses different words, and three words is the ceiling.
 */
class YouTubeTitleLinter
{
    public const DESKTOP = 60;

    public const MOBILE = 40;

    public const HARD = 100;

    /** @var array<string, true> */
    private const VAGUE = [
        'amazing' => true, 'incredible' => true, 'insane' => true, 'crazy' => true,
        'huge' => true, 'massive' => true, 'ultimate' => true, 'best' => true,
        'powerful' => true, 'secret' => true, 'revolutionary' => true, 'epic' => true,
        'perfect' => true, 'complete' => true, 'everything' => true, 'mindblowing' => true,
    ];

    /** @var array<string, true> */
    private const STOP = [
        'the' => true, 'a' => true, 'an' => true, 'of' => true, 'for' => true, 'to' => true,
        'in' => true, 'on' => true, 'and' => true, 'or' => true, 'is' => true, 'are' => true,
        'with' => true, 'your' => true, 'you' => true, 'my' => true, 'i' => true,
        'this' => true, 'that' => true, 'it' => true, 'how' => true, 'what' => true, 'why' => true,
    ];

    public function __construct(private readonly YouTubeWords $words) {}

    /**
     * @param  list<string>  $names  Names this account already has. A name counts. A missing figure is not invented.
     * @return array{title: string, chars: int, score: int, issues: list<array{0: string, 1: string}>, good: list<string>}
     */
    public function check(string $title, ?string $thumb = null, array $names = []): array
    {
        $title = trim($title);
        $length = mb_strlen($title);
        $issues = [];
        $good = [];

        if ($length > self::HARD) {
            $issues[] = ['length', "{$length} characters. YouTube's hard limit is ".self::HARD];
        } elseif ($length > self::DESKTOP) {
            $issues[] = ['length', "{$length} characters. Desktop search cuts near ".self::DESKTOP];
        } else {
            $good[] = "{$length} characters, inside the ".self::DESKTOP.'-character desktop cut';
        }

        if ($length > self::MOBILE) {
            $head = mb_substr($title, 0, self::MOBILE);
            $space = mb_strrpos($head, ' ');
            $shown = $space === false ? $head : mb_substr($head, 0, $space);
            $issues[] = ['mobile', 'a mobile feed shows about "'.$shown.'..." — check the subject survives'];
        }

        $shouting = [];
        foreach (preg_split('/\s+/', $title) ?: [] as $word) {
            if (strlen($word) > 2 && $word === strtoupper($word) && preg_match('/[A-Z]/', $word)) {
                $shouting[] = $word;
            }
        }
        if (count($shouting) > 2) {
            $issues[] = ['shouting', count($shouting).' all-caps words. Two is the ceiling before it reads as spam'];
        } elseif ($shouting !== []) {
            $good[] = count($shouting).' all-caps word for emphasis';
        }

        $vague = [];
        foreach ($this->words->tokens($title) as $word) {
            if (isset(self::VAGUE[$word])) {
                $vague[$word] = true;
            }
        }
        if ($vague !== []) {
            $issues[] = ['vague', implode(', ', array_keys($vague)).' — swap for a number, a name, or a date'];
        }

        $concrete = $this->concrete($title, $names);
        if ($concrete !== null) {
            $good[] = $concrete;
        } else {
            $issues[] = ['no-number', 'no number, name, or date. The missing figure is omitted, not invented'];
        }

        if (str_ends_with($title, '?')) {
            $good[] = 'open question in the title';
        }

        $front = array_values(array_filter(
            array_slice($this->words->tokens($title), 0, 3),
            fn (string $word) => ! isset(self::STOP[$word]),
        ));
        if ($front === []) {
            $issues[] = ['front-load', 'the first three words are all filler. Move the subject forward'];
        }

        if ($thumb !== null && trim($thumb) !== '') {
            $shared = array_values(array_intersect($this->content($title), $this->content($thumb)));
            if ($shared !== []) {
                $issues[] = ['duplicate', 'thumbnail repeats the title on '.implode(', ', $shared)];
            } else {
                $good[] = 'thumbnail and title carry different words';
            }
            $thumbWords = $this->words->tokens($thumb);
            if (count($thumbWords) > 3) {
                $issues[] = ['thumb-length', count($thumbWords).' words on the thumbnail. Three is the ceiling at feed size'];
            } elseif (count($thumbWords) > 0) {
                $good[] = count($thumbWords).' words on the thumbnail';
            }
        }

        $score = max(0, min(100, 100 - 14 * count($issues) + 4 * count($good)));

        return [
            'title' => $title,
            'chars' => $length,
            'score' => $score,
            'issues' => $issues,
            'good' => $good,
        ];
    }

    public function episodeLabel(string $thumb): bool
    {
        return preg_match('/^episode\s+\d+$/i', trim($thumb)) === 1;
    }

    /**
     * @param  list<string>  $names
     */
    private function concrete(string $title, array $names): ?string
    {
        if (preg_match('/\d/', $title) === 1) {
            return 'carries a number that was already in the source';
        }

        if (preg_match('/\b(19|20)\d{2}\b/', $title) === 1
            || preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $title) === 1
            || preg_match('/\bthis (week|month|year)\b/i', $title) === 1) {
            return 'carries a date';
        }

        $hay = strtolower($title);
        foreach ($names as $name) {
            $name = trim($name);
            if (mb_strlen($name) < 3) {
                continue;
            }
            if (str_contains($hay, strtolower($name))) {
                return 'carries a name this account already uses';
            }
        }

        return null;
    }

    /** @return list<string> */
    private function content(string $text): array
    {
        $words = [];
        foreach ($this->words->tokens($text) as $word) {
            if (! isset(self::STOP[$word])) {
                $words[$word] = true;
            }
        }

        return array_keys($words);
    }
}
