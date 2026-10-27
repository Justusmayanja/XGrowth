<?php

namespace App\Services\YouTube;

/**
 * Five properties. The verdict is 60% the mean and 40% the weakest one.
 * A high score is a reason to look again, not a promise the video will hold.
 */
class YouTubeHookScorer
{
    /** @var list<array{name: string, match: list<string>}> */
    private array $formulas;

    /** @var array<string, true> */
    private const FILLER = [
        'basically' => true, 'actually' => true, 'literally' => true, 'just' => true,
        'really' => true, 'very' => true, 'so' => true, 'kind' => true, 'sort' => true,
        'like' => true, 'guys' => true, 'hey' => true, 'welcome' => true, 'today' => true,
        'video' => true, 'subscribe' => true, 'channel' => true,
    ];

    /** @var array<string, true> */
    private const VAGUE = [
        'amazing' => true, 'incredible' => true, 'insane' => true, 'crazy' => true,
        'huge' => true, 'massive' => true, 'game' => true, 'changer' => true,
        'secret' => true, 'powerful' => true, 'ultimate' => true, 'best' => true,
        'revolutionary' => true, 'mind' => true, 'blowing' => true, 'unbelievable' => true,
    ];

    private const CONCRETE = '/\b(\d[\d,.]*\s?(%|k|m|x|s|h)?|\$\d|\d+\s?(second|minute|hour|day|week|month|year)s?)\b/i';

    private const YOU = '/\b(you|your|you\'re|youre|yourself)\b/i';

    private const STAKE = '/\b(lose|lost|wasting|waste|quit|fail|broke|cost|risk|before|stop|never|die|dying|dead)\b/i';

    private const CURIOSITY = '/\b(why|how|what|which|until|before|but|nobody|almost|except|reason|actually)\b/i';

    public function __construct()
    {
        $path = __DIR__.'/data/hooks.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->formulas = is_array($decoded['hooks'] ?? null) ? $decoded['hooks'] : [];
    }

    /**
     * @return array{hook: string, properties: array{SPECIFICITY: int, ADDRESS: int, STAKES: int, CURIOSITY: int, BREVITY: int}, verdict: int, band: string, formula: string, matched: int}
     */
    public function score(string $hook): array
    {
        $hook = trim($hook);
        $properties = [
            'SPECIFICITY' => $this->specificity($hook),
            'ADDRESS' => $this->address($hook),
            'STAKES' => $this->stakes($hook),
            'CURIOSITY' => $this->curiosity($hook),
            'BREVITY' => $this->brevity($hook),
        ];
        $values = array_values($properties);
        $verdict = (int) round(0.6 * (array_sum($values) / count($values)) + 0.4 * min($values));
        [$name, $hits] = $this->classify($hook);

        return [
            'hook' => $hook,
            'properties' => $properties,
            'verdict' => $verdict,
            'band' => $verdict >= 72 ? 'STRONG' : ($verdict >= 55 ? 'WORKABLE' : 'WEAK'),
            'formula' => $name,
            'matched' => $hits,
        ];
    }

    /** @return array{0: string, 1: int} */
    public function classify(string $text): array
    {
        $best = null;
        $hits = 0;
        foreach ($this->formulas as $formula) {
            $count = 0;
            foreach ($formula['match'] as $pattern) {
                if (preg_match('#'.$pattern.'#i', $text) === 1) {
                    $count++;
                }
            }
            if ($count > $hits) {
                $best = $formula;
                $hits = $count;
            }
        }

        return [$best['name'] ?? 'Unclassified', $hits];
    }

    public function formulaName(string $title): string
    {
        return $this->classify($title)[0];
    }

    private function specificity(string $text): int
    {
        preg_match_all("/[a-z0-9'%$.]+/", strtolower($text), $words);
        $tokens = $words[0];
        if ($tokens === []) {
            return 0;
        }
        preg_match_all(self::CONCRETE, $text, $numbers);
        $vague = 0;
        $filler = 0;
        foreach ($tokens as $token) {
            if (isset(self::VAGUE[$token])) {
                $vague++;
            }
            if (isset(self::FILLER[$token])) {
                $filler++;
            }
        }
        $score = 34 + count($numbers[0]) * 22 - $vague * 16 - $filler * 5;
        $parts = preg_split('/\s+/', trim($text)) ?: [];
        $named = 0;
        foreach (array_slice($parts, 1) as $part) {
            if ($part !== '' && preg_match('/^\p{Lu}/u', $part) === 1) {
                $named++;
            }
        }
        $score += min(18, 6 * $named);

        return max(0, min(100, $score));
    }

    private function address(string $text): int
    {
        preg_match_all(self::YOU, $text, $matches);
        $parts = preg_split('/\s+/', trim($text)) ?: [];
        $first = implode(' ', array_slice($parts, 0, 6));
        $bonus = preg_match(self::YOU, $first) === 1 ? 30 : 0;

        return max(0, min(100, 26 + count($matches[0]) * 20 + $bonus));
    }

    private function stakes(string $text): int
    {
        preg_match_all(self::STAKE, $text, $matches);
        $bonus = preg_match(self::CONCRETE, $text) === 1 ? 14 : 0;

        return max(0, min(100, 22 + count($matches[0]) * 26 + $bonus));
    }

    private function curiosity(string $text): int
    {
        preg_match_all(self::CURIOSITY, $text, $matches);
        $question = str_ends_with(trim($text), '?') ? 18 : 0;
        $closed = preg_match('/\b(because|so that|which means)\b/i', $text) === 1 ? -18 : 0;

        return max(0, min(100, 24 + count($matches[0]) * 17 + $question + $closed));
    }

    private function brevity(string $text): int
    {
        preg_match_all("/[a-z0-9'%$.]+/", strtolower($text), $words);
        $count = count($words[0]);
        if ($count === 0) {
            return 0;
        }
        if ($count >= 9 && $count <= 24) {
            return 100;
        }
        if ($count < 9) {
            return max(30, 100 - (9 - $count) * 11);
        }

        return max(10, 100 - ($count - 24) * 7);
    }
}
