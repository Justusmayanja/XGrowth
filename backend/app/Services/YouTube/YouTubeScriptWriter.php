<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\AICore\AiUsageService;
use App\Services\ApiSpend\ApiSpendService;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

class YouTubeScriptWriter
{
    public function __construct(
        private readonly AiUsageService $usage,
        private readonly PlatformSettingsService $settings,
        private readonly YouTubePackageWriter $packages,
        private readonly YouTubeWords $words,
        private readonly ApiSpendService $budget,
    ) {}

    /**
     * A long piece. Shorts are cut from its transcript later, not written from a niche template.
     *
     * @param  array{topic: string, query?: string, kind?: string, reason?: string, formula?: string|null}  $idea
     * @return array<string, mixed>
     */
    public function piece(User $user, AccountProfile $profile, array $idea, string $format, ?string $lesson): array
    {
        return $this->write($user, $profile, $idea, $format, $lesson, null);
    }

    /**
     * @param  array{number: int, title: string, beat: string}  $episode
     * @param  list<array{name: string, role: string}>  $characters
     * @return array<string, mixed>
     */
    public function episode(
        User $user,
        AccountProfile $profile,
        string $premise,
        array $episode,
        array $characters,
        string $format,
        ?string $lesson,
    ): array {
        unset($characters);
        $idea = [
            'topic' => $episode['title'],
            'query' => $profile->resolvedNiche(),
            'kind' => 'episode',
            'reason' => $episode['beat'],
            'score' => 70,
        ];

        return $this->write($user, $profile, $idea, $format, $lesson, [
            'number' => $episode['number'],
            'title' => $episode['title'],
            'beat' => $episode['beat'],
            'premise' => $premise,
        ]);
    }

    /**
     * @param  array{topic: string, query?: string, kind?: string, reason?: string}  $idea
     * @param  array{number: int, title: string, beat: string, premise: string}|null  $episode
     * @return array<string, mixed>
     */
    private function write(User $user, AccountProfile $profile, array $idea, string $format, ?string $lesson, ?array $episode): array
    {
        $goals = $profile->goalsFor('youtube');
        $local = $this->packages->compose($profile, $goals, $idea, $format, $lesson, $episode);
        $model = $this->fromModel($user, $profile, $idea, $format, $lesson, $episode);
        if ($model === null) {
            return $local;
        }

        return $this->overlay($local, $model, $profile, $idea);
    }

    /**
     * @param  array<string, mixed>  $idea
     * @param  array{number: int, title: string, beat: string, premise: string}|null  $episode
     * @return array<string, mixed>|null
     */
    private function fromModel(User $user, AccountProfile $profile, array $idea, string $format, ?string $lesson, ?array $episode): ?array
    {
        $key = config('openai.api_key');
        if (! is_string($key) || $key === '' || ! $this->usage->canUse($user, AiUsageService::ACTION_GENERATION)) {
            return null;
        }
        if (! $this->budget->canSpend($user->id, ApiSpendService::SCRIPT_RESERVE_MILLS)) {
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
            'rules' => [
                'Write five titles at or under 60 characters and five hooks. Keep the shape even if a later step only shows two.',
                'Do not invent a number, a result, or a source. Omit the figure.',
                'The thumbnail is at most three words and does not repeat the title or the first on-screen line. Never "Episode N".',
                'First 15 seconds: confirm the title, open a question, prove the payoff exists.',
                'From 15 to 45 seconds: say what the video will do and start doing it. No intro, no subscribe pitch.',
                'Mark every beat with [ON SCREEN: ...]. Deliver the payoff and say so. One ask at the end.',
                'Description: three search queries in the title and the first two lines when this is for search. Otherwise say it is for people who already watch.',
            ],
        ];

        try {
            $response = OpenAI::chat()->create([
                'model' => $this->settings->getOpenAiModel(),
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => json_encode($payload, JSON_THROW_ON_ERROR)],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.7,
                'max_tokens' => 1800,
            ]);
        } catch (Throwable $e) {
            Log::warning('YouTube script fell back to the local writer', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $parsed = json_decode($response->choices[0]->message->content ?? '', true);
        if (! is_array($parsed)) {
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
            'metadata' => ['format' => $format, 'topic' => $idea['topic'] ?? ''],
        ]);

        return $parsed;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the voice of this one channel. Stay inside the account description. Return JSON with title, titles, hooks, spoken, description, thumbnail, thumbnail_brief, on_screen, shots, payoff, ask, queries, chapters, winning_formula.
titles is five strings, each at most 60 characters, at most two all-caps words. A name the account already uses beats an adjective. If you do not have a number, omit it.
hooks is five opening lines. Do not return one hook, and do not use a slice of the spoken script as the hook.
spoken marks every beat with [ON SCREEN: ...]. The first 15 seconds confirm the title, open a question the viewer cannot close, and prove the payoff exists. From there to 45 seconds, say what the video will do and start doing it. No channel intro, no "before we get started", no subscribe pitch. Deliver the payoff and say that you are delivering it. One ask at the end.
The thumbnail is at most three words, different from the title and from the first on-screen line. Never "Episode N".
If the channel is for search, the title and the first two description lines contain the query words. Then a link only if one was supplied, then chapters, then what is covered, who it is for, and what it assumes. Chapters start at 0:00, at least three, each at least 10 seconds, titles of three to five words. If the channel is for the subscriber feed, say so.
Tags, if you include them, are only spellings, tool names, and abbreviations, fifteen at most.
Do not claim the viewer does not need an expensive version unless the description says that.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $model
     * @param  array{topic?: string, reason?: string, query?: string}  $idea
     * @return array<string, mixed>
     */
    private function overlay(array $local, array $model, AccountProfile $profile, array $idea): array
    {
        $spoken = trim((string) ($model['spoken'] ?? ''));
        $allowed = $profile->resolvedNiche().' '.$profile->description.' '.$profile->audience.' '.($idea['topic'] ?? '').' '.($idea['reason'] ?? '');
        if ($this->spokenOk($spoken, $allowed)) {
            $local['spoken'] = $spoken;
            $local['runtime_words'] = $this->words->wordCount($spoken);
            $local['runtime_seconds'] = (int) round($local['runtime_words'] / 150 * 60);
        }

        return $local;
    }

    private function spokenOk(string $spoken, string $allowed): bool
    {
        if ($spoken === '' || ! str_contains($spoken, '[ON SCREEN:')) {
            return false;
        }
        $lower = strtolower($spoken);
        foreach (['before we get started', 'welcome back', 'you do not need the expensive version', "you don't need the expensive version"] as $banned) {
            if (str_contains($lower, $banned)) {
                return false;
            }
        }
        $first = implode(' ', array_slice(preg_split('/\s+/', $spoken) ?: [], 0, 80));
        if (preg_match('/\bsubscribe\b/i', $first) === 1) {
            return false;
        }
        if (! str_contains($lower, 'this video is delivering') && ! str_contains($lower, 'that is the')) {
            return false;
        }
        $allowedDigits = $this->words->digits($allowed);
        foreach ($this->words->digits($spoken) as $digit) {
            if (! in_array($digit, $allowedDigits, true)) {
                return false;
            }
        }

        return true;
    }
}
