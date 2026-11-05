<?php

namespace App\Services\AICore;

/**
 * Keeps an X post from reading like a template. The check is a second pass,
 * used only when the first draft already sounds written.
 */
class HumanVoice
{
    /** @var list<string> */
    private const PHRASES = [
        "here's the thing",
        'heres the thing',
        "let's be honest",
        'lets be honest',
        'the truth is',
        'at the end of the day',
        'in a world',
        "in today's world",
        'game changer',
        'game-changer',
        'hot take',
        'let that sink in',
        'buckle up',
        "it's not just",
        'its not just',
        'delve',
        'tapestry',
        'elevate your',
        'unlock your',
        'moreover',
        'furthermore',
        'in conclusion',
        'as we navigate',
        'spoiler alert',
        'mark my words',
        'make no mistake',
        'the reality is',
        "here's why",
        'let me explain',
        'unpopular opinion',
        'dive into',
        'let us unpack',
    ];

    public function applies(string $contentType): bool
    {
        return in_array($contentType, ['tweet', 'quote'], true);
    }

    public function instructions(): string
    {
        return <<<'TEXT'

Write it the way this person would type it.
- Match the length and rhythm of their recent posts when those are listed. A short poster gets a short draft.
- Change the shape from post to post: a fragment, a question, one sentence, or two short lines. Do not default to a claim plus an explanation.
- Contractions are fine. Starting without a capital is fine when their posts do that. An unfinished thought is fine.
- Do not open with the niche, or with "as a", or with a label for who is speaking.
- No hashtags. No emojis unless their recent posts use them.
- Never use a slogan, a newsletter lede, or a setup line such as "here's the thing", "let's be honest", "the truth is", "at the end of the day", "it's not X, it's Y", "hot take", or "let that sink in".
- One em dash at most. Do not write a brand statement.
TEXT;
    }

    public function rewriteNote(): string
    {
        return 'The previous draft sounds like a template. Rewrite every draft in the same JSON shape. Keep the point. Write it the way a person types a text. No slogan, no setup line, no hashtags.';
    }

    public function soundsRobotic(string $text): bool
    {
        $haystack = mb_strtolower($text);
        foreach (self::PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        if (preg_match('/^(as a|as an)\b/iu', ltrim($text)) === 1) {
            return true;
        }

        if (preg_match("/it.?s not .{0,48}, it.?s /iu", $text) === 1) {
            return true;
        }

        return substr_count($text, '—') >= 2;
    }

    /**
     * @param  list<array{content?: string}>  $variants
     */
    public function anyRobotic(array $variants): bool
    {
        foreach ($variants as $variant) {
            if ($this->soundsRobotic((string) ($variant['content'] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}
