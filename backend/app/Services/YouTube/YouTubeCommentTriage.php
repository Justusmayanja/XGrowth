<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;

/**
 * Sort comments before any reply is written. The channel voice is the account
 * description. A reply never promises a video the account has not already agreed to make.
 */
class YouTubeCommentTriage
{
    public function __construct(private readonly YouTubeWords $words) {}

    /**
     * @return array{counts: array{questions: int, corrections: int, praise: int, bait: int}, replies: list<array{pile: string, comment: string, reply: string}>, pin: array{comment: string, why: string}|null, topics: list<array{question: string, count: int}>}
     */
    public function triage(string $pasted, AccountProfile $profile, string $script): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $pasted) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $emoji = $this->words->hasEmoji((string) $profile->description.' '.(string) $profile->tone);
        $piles = ['questions' => [], 'corrections' => [], 'praise' => [], 'bait' => [], 'aside' => []];
        foreach ($lines as $line) {
            $piles[$this->pile($line)][] = $line;
        }

        $replies = [];
        foreach ($piles['questions'] as $comment) {
            $replies[] = [
                'pile' => 'questions',
                'comment' => $comment,
                'reply' => $this->answer($comment, $script, $emoji),
            ];
        }
        foreach ($piles['corrections'] as $comment) {
            $reply = $this->correction($comment, $script, $emoji);
            if ($reply === null) {
                continue;
            }
            $replies[] = ['pile' => 'corrections', 'comment' => $comment, 'reply' => $reply];
        }
        $praised = 0;
        foreach ($piles['praise'] as $comment) {
            if ($praised >= 2) {
                break;
            }
            $replies[] = [
                'pile' => 'praise',
                'comment' => $comment,
                'reply' => $this->praise($comment, $emoji),
            ];
            $praised++;
        }

        $topics = $this->topics($piles['questions'], $profile);

        return [
            'counts' => [
                'questions' => count($piles['questions']),
                'corrections' => count($piles['corrections']),
                'praise' => count($piles['praise']),
                'bait' => count($piles['bait']),
            ],
            'replies' => $replies,
            'pin' => $this->pin($piles['questions']),
            'topics' => $topics,
        ];
    }

    private function pile(string $comment): string
    {
        if (preg_match('/\b(ratio|cope|clown|idiot|stupid|shut up|nobody cares|bot|fake news)\b/i', $comment) === 1) {
            return 'bait';
        }
        if (str_contains($comment, '?') || preg_match('/^(how|why|what|when|where|who|can|should|do|does|is|are)\b/i', $comment) === 1) {
            return 'questions';
        }
        if (preg_match('/\b(actually|correction|you meant|that is wrong|that\'s wrong|not true|incorrect|you said)\b/i', $comment) === 1) {
            return 'corrections';
        }
        if (preg_match('/\b(love|loved|great|helpful|thank|thanks|useful|clear|exactly)\b/i', $comment) === 1) {
            return 'praise';
        }

        return 'aside';
    }

    private function answer(string $comment, string $script, bool $emoji): string
    {
        $script = trim(preg_replace('/\[ON SCREEN:[^\]]*\]/', ' ', $script) ?? $script);
        $sentence = 'This video already answers that from the description of the channel.';
        if (preg_match('/That is [^.]+/i', $script, $match) === 1) {
            $sentence = 'It is in this video. '.rtrim($match[0], '.').'.';
        }
        unset($comment);

        return $this->finish($sentence, $emoji);
    }

    private function correction(string $comment, string $script, bool $emoji): ?string
    {
        $window = $this->quoted($comment, $script);
        if ($window === null) {
            return null;
        }

        return $this->finish('You are right. The video does say "'.$window.'".', $emoji);
    }

    private function praise(string $comment, bool $emoji): string
    {
        $specific = $this->words->content($comment)[0] ?? 'that';

        return $this->finish('Glad you mentioned '.$specific.'.', $emoji);
    }

    private function finish(string $reply, bool $emoji): string
    {
        if (! $emoji) {
            $reply = preg_replace('/\p{Extended_Pictographic}/u', '', $reply) ?? $reply;
        }
        $reply = $this->words->limitWords(trim($reply), 30);
        if (preg_match('/\b(next video|i will make|i\'ll make|coming soon)\b/i', $reply) === 1) {
            return 'It is already in this video.';
        }

        return $reply;
    }

    private function quoted(string $comment, string $script): ?string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($comment, " \t\n\r\0\x0B\"'"))) ?: [];
        $hay = strtolower($script);
        $count = count($tokens);
        for ($size = min(6, $count); $size >= 3; $size--) {
            for ($i = 0; $i <= $count - $size; $i++) {
                $phrase = implode(' ', array_slice($tokens, $i, $size));
                if (strlen($phrase) >= 12 && str_contains($hay, $phrase)) {
                    return $phrase;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $questions
     * @return array{comment: string, why: string}|null
     */
    private function pin(array $questions): ?array
    {
        if ($questions === []) {
            return null;
        }

        $best = $questions[0];
        $bestCount = 1;
        foreach ($questions as $question) {
            $count = 0;
            $words = $this->words->content($question);
            foreach ($questions as $other) {
                $shared = array_intersect($words, $this->words->content($other));
                if (count($shared) >= 3 || strtolower($question) === strtolower($other)) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $best = $question;
                $bestCount = $count;
            }
        }

        return [
            'comment' => $best,
            'why' => $bestCount > 1
                ? 'Pin this. It is the question the other comments are also asking.'
                : 'Pin this. It is the question in the pile.',
        ];
    }

    /**
     * @param  list<string>  $questions
     * @return list<array{question: string, count: int}>
     */
    private function topics(array $questions, AccountProfile $profile): array
    {
        $boundary = $this->words->content(
            $profile->resolvedNiche().' '.$profile->description.' '.$profile->audience
        );
        $clusters = [];
        foreach ($questions as $question) {
            $words = $this->words->content($question);
            if ($words === [] || array_intersect($words, $boundary) === []) {
                continue;
            }
            $placed = false;
            foreach ($clusters as &$cluster) {
                if (count(array_intersect($words, $cluster['words'])) >= 3) {
                    $cluster['count']++;
                    $placed = true;
                    break;
                }
            }
            unset($cluster);
            if (! $placed) {
                $clusters[] = ['question' => $question, 'count' => 1, 'words' => $words];
            }
        }

        $topics = [];
        foreach ($clusters as $cluster) {
            if ($cluster['count'] >= 2) {
                $topics[] = ['question' => $cluster['question'], 'count' => $cluster['count']];
            }
        }

        return $topics;
    }
}
