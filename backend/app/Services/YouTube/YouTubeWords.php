<?php

namespace App\Services\YouTube;

class YouTubeWords
{
    /** @var array<string, true> */
    private const STOP = [
        'the' => true, 'a' => true, 'an' => true, 'of' => true, 'for' => true, 'to' => true,
        'in' => true, 'on' => true, 'and' => true, 'or' => true, 'is' => true, 'are' => true,
        'was' => true, 'were' => true, 'be' => true, 'with' => true, 'your' => true, 'you' => true,
        'my' => true, 'i' => true, 'this' => true, 'that' => true, 'it' => true, 'how' => true,
        'what' => true, 'why' => true, 'who' => true, 'when' => true, 'from' => true, 'as' => true,
        'at' => true, 'by' => true, 'but' => true, 'so' => true, 'if' => true, 'not' => true,
        'do' => true, 'does' => true, 'did' => true, 'just' => true, 'about' => true, 'into' => true,
        'they' => true, 'them' => true, 'we' => true, 'our' => true, 'their' => true,
    ];

    /** @return list<string> */
    public function tokens(string $text): array
    {
        preg_match_all("/[a-z0-9']+/", strtolower($text), $matches);

        return $matches[0];
    }

    /** @return list<string> */
    public function content(string $text): array
    {
        $words = [];
        foreach ($this->tokens($text) as $word) {
            if (strlen($word) < 4 || isset(self::STOP[$word])) {
                continue;
            }
            $words[$word] = true;
        }

        return array_keys($words);
    }

    public function cut(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $slice = mb_substr($text, 0, $limit);
        $space = mb_strrpos($slice, ' ');
        $cut = rtrim($space === false ? $slice : mb_substr($slice, 0, $space), " ,;:-");

        return $cut !== '' ? $cut : $slice;
    }

    public function limitWords(string $text, int $max): string
    {
        $parts = preg_split('/\s+/', trim($text)) ?: [];
        if (count($parts) <= $max) {
            return trim($text);
        }

        return implode(' ', array_slice($parts, 0, $max));
    }

    public function wordCount(string $text): int
    {
        $plain = preg_replace('/\[ON SCREEN:[^\]]*\]/', ' ', $text) ?? $text;

        return str_word_count($plain);
    }

    /** @return list<string> */
    public function digits(string $text): array
    {
        preg_match_all('/\d+/', $text, $matches);

        return $matches[0];
    }

    public function hasEmoji(string $text): bool
    {
        return preg_match('/\p{Extended_Pictographic}/u', $text) === 1;
    }
}
