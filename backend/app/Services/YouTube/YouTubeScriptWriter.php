<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\AICore\AiUsageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

class YouTubeScriptWriter
{
    public function __construct(
        private readonly AiUsageService $usage,
        private readonly PlatformSettingsService $settings,
    ) {}

    /**
     * @param  array{topic: string, query: string, kind: string, reason: string, score: float}  $idea
     * @return array{title: string, hook: string, spoken: string, description: string, thumbnail: string, on_screen: list<array{text: string, at: string}>, shots: list<array{prompt: string, seconds: int, kind: string}>, kind: string}
     */
    public function short(User $user, AccountProfile $profile, array $idea, string $format, ?string $lesson): array
    {
        $fromModel = $this->fromModel($user, $profile, $idea, $format, $lesson, episode: null);

        return $fromModel ?? $this->localShort($profile, $idea, $format, $lesson);
    }

    /**
     * @param  array{number: int, title: string, beat: string}  $episode
     * @param  list<array{name: string, role: string}>  $characters
     * @return array{title: string, hook: string, spoken: string, description: string, thumbnail: string, on_screen: list<array{text: string, at: string}>, shots: list<array{prompt: string, seconds: int, kind: string}>, kind: string}
     */
    public function episode(
        User $user,
        AccountProfile $profile,
        string $premise,
        array $episode,
        array $characters,
        ?string $lesson,
    ): array {
        $idea = [
            'topic' => $episode['title'],
            'query' => $profile->resolvedNiche(),
            'kind' => 'episode',
            'reason' => $episode['beat'],
            'score' => 70,
        ];
        $fromModel = $this->fromModel($user, $profile, $idea, 'series', $lesson, [
            'premise' => $premise,
            'episode' => $episode,
            'characters' => $characters,
        ]);

        return $fromModel ?? $this->localEpisode($profile, $premise, $episode, $characters, $lesson);
    }

    /**
     * @param  array{topic: string, query: string, kind: string, reason: string, score: float}  $idea
     * @param  array{premise: string, episode: array{number: int, title: string, beat: string}, characters: list<array{name: string, role: string}>}|null  $episode
     * @return array{title: string, hook: string, spoken: string, description: string, thumbnail: string, on_screen: list<array{text: string, at: string}>, shots: list<array{prompt: string, seconds: int, kind: string}>, kind: string}|null
     */
    private function fromModel(User $user, AccountProfile $profile, array $idea, string $format, ?string $lesson, ?array $episode): ?array
    {
        $key = config('openai.api_key');
        if (! is_string($key) || $key === '' || ! $this->usage->canUse($user, AiUsageService::ACTION_GENERATION)) {
            return null;
        }

        $payload = [
            'niche' => $profile->resolvedNiche(),
            'audience' => $profile->audience,
            'voice' => $profile->tone,
            'description' => $profile->description,
            'format' => $format,
            'idea' => $idea,
            'lesson' => $lesson,
            'episode' => $episode,
        ];

        try {
            $response = OpenAI::chat()->create([
                'model' => $this->settings->getOpenAiModel(),
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($format)],
                    ['role' => 'user', 'content' => json_encode($payload, JSON_THROW_ON_ERROR)],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.7,
                'max_tokens' => 900,
            ]);
        } catch (Throwable $e) {
            Log::warning('YouTube script fell back to the local writer', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $parsed = json_decode($response->choices[0]->message->content ?? '', true);
        if (! is_array($parsed) || trim((string) ($parsed['spoken'] ?? '')) === '') {
            return null;
        }

        $inputTokens = (int) ($response->usage->promptTokens ?? 0);
        $outputTokens = (int) ($response->usage->completionTokens ?? 0);
        $this->usage->record($user, [
            'feature' => 'youtube_script',
            'model' => $this->settings->getOpenAiModel(),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => (int) ($response->usage->totalTokens ?? ($inputTokens + $outputTokens)),
            'web_search_calls' => 0,
            'used_web_search' => false,
            'metadata' => ['format' => $format, 'topic' => $idea['topic']],
        ]);

        return $this->normalize($parsed, $idea, $format);
    }

    private function systemPrompt(string $format): string
    {
        $shape = $format === 'series'
            ? 'Write one episode: cold open, three scenes, and a last line that makes the next episode necessary. About 400 words.'
            : 'Write a YouTube Short of 15 to 25 seconds. Spoken lines only, no stage directions in the spoken field.';

        return <<<PROMPT
You write for one YouTube channel. Return JSON with title, hook, spoken, description, thumbnail, on_screen, shots.
{$shape}
The first two lines of description must make sense alone in search. No keyword lists.
on_screen is a list of {text, at} with at like 0:00. Keep each line under 8 words.
shots is a list of {prompt, seconds, kind}. kind is "clip" or "still". Text-on-screen formats use an empty shots list.
Stay inside the account description. Do not invent a different niche.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array{topic: string, kind: string}  $idea
     * @return array{title: string, hook: string, spoken: string, description: string, thumbnail: string, on_screen: list<array{text: string, at: string}>, shots: list<array{prompt: string, seconds: int, kind: string}>, kind: string}
     */
    private function normalize(array $parsed, array $idea, string $format): array
    {
        $onScreen = [];
        foreach (array_slice(is_array($parsed['on_screen'] ?? null) ? $parsed['on_screen'] : [], 0, 8) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $text = trim((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $onScreen[] = ['text' => Str::limit($text, 80, ''), 'at' => (string) ($line['at'] ?? '0:00')];
        }

        $shots = [];
        if ($format !== 'text_on_screen') {
            foreach (array_slice(is_array($parsed['shots'] ?? null) ? $parsed['shots'] : [], 0, 6) as $shot) {
                if (! is_array($shot)) {
                    continue;
                }
                $prompt = trim((string) ($shot['prompt'] ?? ''));
                if ($prompt === '') {
                    continue;
                }
                $shots[] = [
                    'prompt' => $prompt,
                    'seconds' => max(4, min(8, (int) ($shot['seconds'] ?? 8))),
                    'kind' => ($shot['kind'] ?? '') === 'still' ? 'still' : 'clip',
                ];
            }
        }

        return [
            'title' => Str::limit(trim((string) ($parsed['title'] ?? $idea['topic'])), 90, ''),
            'hook' => Str::limit(trim((string) ($parsed['hook'] ?? '')), 180, ''),
            'spoken' => trim((string) $parsed['spoken']),
            'description' => trim((string) ($parsed['description'] ?? '')),
            'thumbnail' => Str::limit(trim((string) ($parsed['thumbnail'] ?? '')), 80, ''),
            'on_screen' => $onScreen,
            'shots' => $shots,
            'kind' => $idea['kind'],
        ];
    }

    /**
     * @param  array{topic: string, query: string, kind: string, reason: string, score: float}  $idea
     * @return array{title: string, hook: string, spoken: string, description: string, thumbnail: string, on_screen: list<array{text: string, at: string}>, shots: list<array{prompt: string, seconds: int, kind: string}>, kind: string}
     */
    private function localShort(AccountProfile $profile, array $idea, string $format, ?string $lesson): array
    {
        $niche = $profile->resolvedNiche();
        $audience = trim((string) $profile->audience) ?: 'the people this is for';
        $opening = $lesson
            ? 'Last time the opening lost people, so this one starts on the result.'
            : "This is for {$audience}.";

        $spoken = match ($idea['kind']) {
            'list' => "{$opening} Three steps in {$niche}. One: do the smallest useful version. Two: stop when it works. Three: repeat it once before you change it.",
            'mistake' => "{$opening} The {$niche} mistake is starting with the tool instead of the result. Pick the result first. Then the rest is obvious.",
            'myth' => "{$opening} The common line about {$niche} is wrong. You do not need the expensive version. You need one finished version.",
            'question' => "{$opening} The question is how to start {$niche} without a whole weekend. The answer is one sitting and one outcome.",
            'budget' => "{$opening} You can start {$niche} with what you already have. Spend time on the result, not on new gear.",
            default => "{$opening} Here is a {$niche} result from one sitting. Watch it through, then do the same step tonight.",
        };

        $lines = match ($idea['kind']) {
            'list' => ['Three steps', 'Smallest version', 'Stop when it works', 'Repeat it once'],
            'mistake' => ['Wrong place to start', 'Pick the result', 'Then the rest'],
            'myth' => ['That line is wrong', 'Skip the expensive version', 'Finish one'],
            'question' => ['The real question', 'One sitting', 'One outcome'],
            'budget' => ['Use what you have', 'Spend time, not money', 'Ship the result'],
            default => ['One sitting', 'One result', 'Do it tonight'],
        };

        $onScreen = [];
        foreach ($lines as $index => $line) {
            $onScreen[] = ['text' => $line, 'at' => sprintf('0:%02d', $index * 4)];
        }

        $shots = [];
        if ($format !== 'text_on_screen') {
            $visual = "Vertical 9:16. No logos, no on-screen text. A plain scene about {$niche}.";
            $shots = [
                ['prompt' => $visual.' Open on the result, not the setup.', 'seconds' => 8, 'kind' => 'clip'],
                ['prompt' => $visual.' A closer view of the one step that matters.', 'seconds' => 8, 'kind' => 'clip'],
            ];
        }

        return [
            'title' => Str::limit($idea['topic'], 90, ''),
            'hook' => Str::limit($spoken, 140, ''),
            'spoken' => $spoken,
            'description' => $idea['topic']."\n".$idea['reason']."\n\nMade for {$audience}.",
            'thumbnail' => Str::limit($lines[0], 40, ''),
            'on_screen' => $onScreen,
            'shots' => $shots,
            'kind' => $idea['kind'],
        ];
    }

    /**
     * @param  array{number: int, title: string, beat: string}  $episode
     * @param  list<array{name: string, role: string}>  $characters
     * @return array{title: string, hook: string, spoken: string, description: string, thumbnail: string, on_screen: list<array{text: string, at: string}>, shots: list<array{prompt: string, seconds: int, kind: string}>, kind: string}
     */
    private function localEpisode(AccountProfile $profile, string $premise, array $episode, array $characters, ?string $lesson): array
    {
        $niche = $profile->resolvedNiche();
        $host = $characters[0]['name'] ?? 'The host';
        $other = $characters[1]['name'] ?? 'A viewer';
        $lessonLine = $lesson ? "{$lesson}\n\n" : '';
        $number = $episode['number'];
        $next = $number + 1;

        $spoken = <<<TEXT
{$lessonLine}EPISODE {$number}. {$episode['title']}

COLD OPEN
{$host} is in the middle of {$niche}. {$episode['beat']} The scene starts on the problem, before any explanation.

SCENE TWO
{$other} asks the question the audience would ask. {$host} answers with one concrete step from the account, not a list of tips. {$premise}

SCENE THREE
The step works, then a new problem shows up that this episode cannot finish. {$host} names it out loud.

END OF EPISODE
The last line points at episode {$next}. The story does not reset. What changed here is still true next time.
TEXT;

        return [
            'title' => Str::limit("Episode {$number}: {$episode['title']}", 90, ''),
            'hook' => $episode['beat'],
            'spoken' => trim($spoken),
            'description' => "Episode {$number}. {$episode['title']}\n{$episode['beat']}\n\nPart of a series about {$niche}.",
            'thumbnail' => "Episode {$number}",
            'on_screen' => [
                ['text' => "Episode {$number}", 'at' => '0:00'],
                ['text' => Str::limit($episode['title'], 60, ''), 'at' => '0:04'],
            ],
            'shots' => [
                ['prompt' => "Still. {$host} dealing with {$niche}, no text, no logos.", 'seconds' => 8, 'kind' => 'still'],
                ['prompt' => "Still. The one step from this episode, shown plainly.", 'seconds' => 8, 'kind' => 'still'],
                ['prompt' => "Still. The unfinished problem that leads to episode {$next}.", 'seconds' => 8, 'kind' => 'still'],
            ],
            'kind' => 'episode',
        ];
    }
}
