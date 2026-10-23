<?php

namespace App\Services\AICore;

use App\Models\AccountProfile;
use App\Models\AiRule;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Responses\Output\OutputWebSearchToolCall;
use RuntimeException;

class AICoreService
{
    public function __construct(
        private readonly AiUsageService $usage,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function isConfigured(): bool
    {
        $key = config('openai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @param  array{
     *     user: User,
     *     topic: string,
     *     tone?: string,
     *     niche?: string,
     *     goal?: string,
     *     content_type?: string,
     *     audience?: string|null,
     *     description?: string|null,
     *     use_web_search?: bool
     * }  $params
     * @return array{
     *     variants: list<array{content: string, virality_score: int, estimated_reach: string}>,
     *     note: string,
     *     usage: array<string, mixed>
     * }
     */
    public function generateContentVariants(array $params): array
    {
        $this->ensureConfigured();

        /** @var User $user */
        $user = $params['user'];
        $useWebSearch = (bool) ($params['use_web_search'] ?? false) && $this->settings->getBool('web_search_enabled', true);

        $this->usage->assertCanGenerate($user);
        if ($useWebSearch) {
            $this->usage->assertCanWebSearch($user);
        }

        $topic = $params['topic'];
        $tone = $params['tone'] ?? 'professional';
        $niche = $params['niche'] ?? 'general';
        $goal = $params['goal'] ?? 'grow an audience on X, TikTok, and YouTube';
        $contentType = $params['content_type'] ?? 'tweet';
        $variantCount = max(1, min(3, (int) ($params['variant_count'] ?? 3)));

        $systemPrompt = $this->buildSystemPrompt(
            'content_generation',
            'You draft posts for X, TikTok, and YouTube. Match the requested format. Write the way this account already sounds, with a specific claim and no filler.',
            includeContentMix: true,
        );

        $suggest = $params['suggest'] ?? null;

        $userPrompt = $suggest
            ? "Generate exactly {$variantCount} distinct {$contentType} drafts. No topic was given: choose the best thing for this account to post right now. Each draft must take a different angle.\n\n"
            : "Generate exactly {$variantCount} distinct {$contentType} draft options about: {$topic}\n\n";
        $userPrompt .= "User profile:\n";
        $userPrompt .= "- Niche: {$niche}\n";
        $userPrompt .= "- Tone: {$tone}\n";
        $userPrompt .= "- Goal: {$goal}\n";

        if (! empty($params['purpose'])) {
            $userPrompt .= '- Using Mackie17 for: '.$params['purpose']."\n";
        }

        if (! empty($params['audience'])) {
            $userPrompt .= '- Target audience: '.$params['audience']."\n";
        }

        if (! empty($params['description'])) {
            $userPrompt .= '- About them: '.$params['description']."\n";
        }

        if ($suggest) {
            $userPrompt .= $this->suggestInstructions($suggest);
        }

        $userPrompt .= "\nFormat: ".$this->formatInstructions($contentType)."\n";

        if ($useWebSearch) {
            $userPrompt .= $suggest
                ? "\nUse web search to check what is happening in this niche today, and verify any facts you use.\n"
                : "\nUse web search to verify current facts, names, dates, and news about this topic before drafting.\n";
        } elseif (($params['content_source'] ?? '') === 'original') {
            $userPrompt .= "\nThis is ORIGINAL evergreen content — do not reference breaking news or current trends.\n";
        }

        $userPrompt .= <<<'PROMPT'

Return JSON:
{
  "variants": [
    {
      "content": "the post text",
      "angle": "what this draft is about, under 60 characters",
      "virality_score": 82,
      "estimated_reach": "high"
    }
  ]
}

Use virality_score as an integer from 0-100 and estimated_reach as "low", "medium", or "high".
PROMPT;

        if ($useWebSearch) {
            $result = $this->responsesJson($systemPrompt, $userPrompt, withWebSearch: true);
        } else {
            $result = $this->chatJson($systemPrompt, $userPrompt);
        }

        $parsed = $result['parsed'];
        $variants = $this->normalizeVariants($parsed['variants'] ?? []);

        if ($variants === []) {
            throw new RuntimeException('OpenAI returned no content variants.');
        }

        $this->usage->record($user, [
            'feature' => 'content_generation',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => $result['web_search_calls'],
            'used_web_search' => $useWebSearch,
            'metadata' => [
                'topic' => $topic,
                'mode' => $suggest ? 'suggest' : 'describe',
                'content_type' => $contentType,
            ],
        ]);

        $note = $suggest
            ? "Picked for {$niche} · {$tone} tone"
            : "Drafts for {$niche} · {$tone} tone";
        if ($useWebSearch && $result['web_search_calls'] > 0) {
            $note .= ' · web search used';
        }

        return [
            'variants' => array_slice($variants, 0, $variantCount),
            'note' => $note,
            'usage' => $this->usage->status($user),
        ];
    }

    /**
     * @param  array{trends?: list<string>, recent?: list<string>, prefer?: string, evergreen?: list<string>}  $suggest
     */
    private function suggestInstructions(array $suggest): string
    {
        $text = "\nWhat to write about:\n";

        if (! empty($suggest['trends'])) {
            $text .= '- Topics moving now: '.implode('; ', $suggest['trends'])."\n";
            $text .= "  Use one only if it genuinely belongs in this niche. Never bend the niche to fit a trend.\n";
        }

        if (! empty($suggest['evergreen'])) {
            $text .= '- Evergreen angles that always fit: '.implode('; ', $suggest['evergreen'])."\n";
        }

        $text .= match ($suggest['prefer'] ?? 'balanced') {
            'original' => "- Their recent posts lean on trends. Make at least two drafts original, not tied to news.\n",
            'trend' => "- Their recent posts are mostly evergreen. Lean timely where a fitting topic exists.\n",
            default => "- Mix one timely draft with evergreen ones when a fitting topic exists.\n",
        };

        if (! empty($suggest['recent'])) {
            $text .= "- Do not repeat these recent posts or their angles:\n";
            foreach ($suggest['recent'] as $post) {
                $text .= '  * '.str_replace("\n", ' ', $post)."\n";
            }
        }

        $text .= "- Pick angles that serve the goal above and that this audience would reply to or share.\n";

        return $text;
    }

    /**
     * @param  list<string>  $platforms
     * @return list<array{niche: string, angle: string}>
     */
    public function suggestNiches(User $user, string $interests, string $weeklyTime, string $presence, array $platforms): array
    {
        $this->ensureConfigured();
        $this->usage->assertCanGenerate($user);

        $systemPrompt = $this->buildSystemPrompt(
            'niche_discovery',
            'You help people who want to earn from content pick a niche they can keep up with. Be concrete. Name an audience and a repeatable format, not a vague topic.',
        );

        $userPrompt = "Suggest exactly 3 content niches for this person.\n\n";
        $userPrompt .= "- What they are into or good at: {$interests}\n";
        $userPrompt .= "- Time they have each week: {$weeklyTime}\n";
        $userPrompt .= "- How they want to appear: {$presence}\n";
        $userPrompt .= '- Platforms: '.implode(', ', $platforms)."\n";
        $userPrompt .= <<<'PROMPT'

Each niche must be something they could post about every week for a year, with a clear way to earn later.
Fit the format to the platforms and to how much time they have.

Return JSON:
{
  "niches": [
    {
      "niche": "short name, under 40 characters",
      "angle": "one sentence: who it is for and what they would post"
    }
  ]
}
PROMPT;

        $result = $this->chatJson($systemPrompt, $userPrompt);

        $niches = [];
        foreach ($result['parsed']['niches'] ?? [] as $item) {
            $niche = trim((string) ($item['niche'] ?? ''));
            $angle = trim((string) ($item['angle'] ?? ''));
            if ($niche !== '' && $angle !== '') {
                $niches[] = ['niche' => mb_substr($niche, 0, 60), 'angle' => mb_substr($angle, 0, 240)];
            }
        }

        if ($niches === []) {
            throw new RuntimeException('OpenAI returned no niche ideas.');
        }

        $this->usage->record($user, [
            'feature' => 'niche_discovery',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => $result['web_search_calls'] ?? 0,
            'used_web_search' => false,
        ]);

        return array_slice($niches, 0, 3);
    }

    /**
     * Fresh post moments for one account. Each call is asked to avoid the last set.
     *
     * @param  list<array{name?: string, suggestion?: string}>  $avoid
     * @param  list<string>  $recentPosts
     * @return list<array{name: string, suggestion: string}>
     */
    public function suggestMoments(User $user, AccountProfile $profile, string $platform, array $avoid = [], array $recentPosts = []): array
    {
        $this->ensureConfigured();
        $this->usage->assertCanGenerate($user);

        $platformName = match ($platform) {
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            default => 'X',
        };
        $niche = $profile->resolvedNiche();
        $tone = str_replace('_', ' ', $profile->primaryTone());
        $goal = $profile->goalSentenceFor($platform);
        $description = trim((string) $profile->description);
        $audience = trim((string) $profile->audience);
        $leans = [
            'a specific number from their work',
            'a small disagreement with common advice',
            'a memory from this week',
            'a question their audience will argue about',
            'a shortcut people get wrong',
            'something they stopped doing',
        ];
        $lean = $leans[random_int(0, count($leans) - 1)];

        $systemPrompt = $this->buildSystemPrompt(
            'desk_moments',
            'You invent specific post moments for one social account. Write like a sharp editor. A moment is something this person could publish today. Never return a category label, the niche name by itself, or a line that starts with "Strong moment", "Good time", or "Watch".',
        );

        $userPrompt = "Invent exactly 4 fresh moments for this {$platformName} account.\n\n";
        $userPrompt .= "- Niche: {$niche}\n";
        $userPrompt .= "- Voice: {$tone}\n";
        $userPrompt .= "- What they are trying to do: {$goal}\n";
        if ($description !== '') {
            $userPrompt .= "- How they describe the account: {$description}\n";
        }
        if ($audience !== '') {
            $userPrompt .= "- Audience: {$audience}\n";
        }
        $userPrompt .= "\nLean this set toward {$lean}.\n";
        $userPrompt .= "Make the four unlike each other: one observation, one short story, one question people will answer, and one opinion.\n";
        $userPrompt .= "Use details only this account would know. Do not write a content calendar.\n\n";
        $userPrompt .= "Do not repeat these earlier moments:\n";
        if ($avoid === []) {
            $userPrompt .= "- None yet. This is the first set.\n";
        } else {
            foreach ($avoid as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    $userPrompt .= '- '.str_replace("\n", ' ', $name)."\n";
                }
            }
        }
        $userPrompt .= "\nDo not reuse these recent posts:\n";
        if ($recentPosts === []) {
            $userPrompt .= "- None yet.\n";
        } else {
            foreach ($recentPosts as $post) {
                $line = trim(str_replace("\n", ' ', $post));
                if ($line !== '') {
                    $userPrompt .= '- '.mb_substr($line, 0, 180)."\n";
                }
            }
        }
        $userPrompt .= <<<'PROMPT'

Return JSON:
{
  "moments": [
    {
      "name": "a concrete hook under 80 characters",
      "suggestion": "one sentence on the angle, under 140 characters"
    }
  ]
}
PROMPT;

        $result = $this->chatJson($systemPrompt, $userPrompt, 1.0);

        $moments = [];
        foreach ($result['parsed']['moments'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $suggestion = trim((string) ($item['suggestion'] ?? ''));
            if ($name === '' || $suggestion === '') {
                continue;
            }
            $moments[] = [
                'name' => mb_substr($name, 0, 90),
                'suggestion' => mb_substr($suggestion, 0, 180),
            ];
        }

        if (count($moments) < 3) {
            throw new RuntimeException('OpenAI returned no post moments.');
        }

        $this->usage->record($user, [
            'feature' => 'desk_moments',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => 0,
            'used_web_search' => false,
        ]);

        return array_slice($moments, 0, 4);
    }

    private function formatInstructions(string $contentType): string
    {
        return match ($contentType) {
            'thread' => 'X thread. Number each post (1/, 2/) on its own line. Each post under 280 characters.',
            'poll' => 'X poll. One question under 280 characters, then four options labeled A-D, each under 25 characters.',
            'quote' => 'X quote-post comment under 280 characters. Add a point. Do not repeat the quoted post.',
            'tiktok_caption' => 'TikTok caption. First line is a spoken hook. Then one or two lines of context. End with 3 to 5 specific hashtags. Under 400 characters.',
            'youtube_title' => 'YouTube title under 70 characters. State the subject. Do not stack punctuation or write in all caps.',
            'youtube_description' => 'YouTube description. The first two lines must make sense alone in search results. Then a short outline of the video. No keyword lists.',
            'youtube_community' => 'YouTube community post under 500 characters. One point, written to people who already watch the channel.',
            default => 'X post under 280 characters. One claim, in the account voice.',
        };
    }

    /**
     * @param  array{
     *     user: User,
     *     context: string,
     *     tone?: string,
     *     niche?: string,
     *     post_content?: string|null,
     *     use_web_search?: bool
     * }  $params
     * @return list<array{content: string, tone: string}>
     */
    public function generateReplyDrafts(array $params): array
    {
        $this->ensureConfigured();

        /** @var User $user */
        $user = $params['user'];
        $useWebSearch = (bool) ($params['use_web_search'] ?? false) && $this->settings->getBool('web_search_enabled', true);

        $this->usage->assertCanGenerate($user);
        if ($useWebSearch) {
            $this->usage->assertCanWebSearch($user);
        }

        $tone = $params['tone'] ?? 'professional';
        $niche = $params['niche'] ?? 'general';
        $context = $params['context'];
        $postContent = $params['post_content'] ?? null;

        $systemPrompt = $this->buildSystemPrompt(
            'reply_drafts',
            'You are an expert at writing high-quality X (Twitter) replies that drive profile visits and meaningful conversation.'
        );

        $userPrompt = "Generate exactly 3 reply drafts in a {$tone} tone for someone in the {$niche} niche.\n";
        $userPrompt .= "Conversation context: {$context}\n";

        if ($postContent) {
            $userPrompt .= "Original post: {$postContent}\n";
        }

        if ($useWebSearch) {
            $userPrompt .= "\nUse web search only if you need current facts to reply accurately.\n";
        }

        $userPrompt .= <<<PROMPT

Return JSON:
{
  "drafts": [
    {
      "content": "reply text",
      "tone": "{$tone}"
    }
  ]
}
PROMPT;

        if ($useWebSearch) {
            $result = $this->responsesJson($systemPrompt, $userPrompt, withWebSearch: true);
        } else {
            $result = $this->chatJson($systemPrompt, $userPrompt);
        }

        $drafts = $this->normalizeReplyDrafts($result['parsed']['drafts'] ?? [], $tone);

        if ($drafts === []) {
            throw new RuntimeException('OpenAI returned no reply drafts.');
        }

        $this->usage->record($user, [
            'feature' => 'reply_drafts',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => $result['web_search_calls'],
            'used_web_search' => $useWebSearch,
        ]);

        return array_slice($drafts, 0, 3);
    }

    /**
     * @deprecated Use generateContentVariants() instead.
     */
    public function generateContent(array $context): array
    {
        return $this->generateContentVariants($context);
    }

    public function generateFromTemplate(string $eventType, array $variables): string
    {
        $this->ensureConfigured();

        $systemPrompt = $this->buildSystemPrompt(
            'event_templates',
            'You write short, punchy X posts for live sports/news moments.'
        );

        $userPrompt = match ($eventType) {
            'goal' => 'Write a GOAL announcement post. Team: '.($variables['team'] ?? 'Team').'. Score: '.($variables['score'] ?? '').'.',
            'transfer' => 'Write a BREAKING transfer news post. Player: '.($variables['player'] ?? '').'. Update: '.($variables['update'] ?? '').'.',
            default => throw new RuntimeException("Unknown event type: {$eventType}"),
        };

        try {
            $response = OpenAI::chat()->create([
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.9,
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenAI template generation failed', ['error' => $e->getMessage(), 'event' => $eventType]);
            throw new RuntimeException('OpenAI request failed: '.$e->getMessage(), previous: $e);
        }

        $content = trim($response->choices[0]->message->content ?? '');

        if ($content === '') {
            throw new RuntimeException('OpenAI returned empty content.');
        }

        return $content;
    }

    private function buildSystemPrompt(string $contextKey, string $role, bool $includeContentMix = false): string
    {
        $keys = $includeContentMix ? [$contextKey, 'content_mix'] : [$contextKey];
        $adminRules = AiRule::systemPromptPrefix(...$keys);

        if ($adminRules === '') {
            return $role;
        }

        return $adminRules."\n\n---\n\nROLE:\n{$role}";
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('OpenAI is not configured. Set OPENAI_API_KEY in your environment.');
        }
    }

    private function model(): string
    {
        return $this->settings->getOpenAiModel();
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     web_search_calls: int
     * }
     */
    private function chatJson(string $system, string $user, float $temperature = 0.8): array
    {
        try {
            $response = OpenAI::chat()->create([
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => $temperature,
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenAI request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('OpenAI request failed: '.$e->getMessage(), previous: $e);
        }

        $content = $response->choices[0]->message->content ?? '';
        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return [
            'parsed' => $parsed,
            'input_tokens' => (int) ($response->usage->promptTokens ?? 0),
            'output_tokens' => (int) ($response->usage->completionTokens ?? 0),
            'total_tokens' => (int) ($response->usage->totalTokens ?? 0),
            'web_search_calls' => 0,
        ];
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     web_search_calls: int
     * }
     */
    private function responsesJson(string $system, string $user, bool $withWebSearch = false): array
    {
        $parameters = [
            'model' => $this->model(),
            'instructions' => $system,
            'input' => $user,
            'temperature' => 0.8,
        ];

        // OpenAI rejects JSON mode combined with the web_search tool.
        if ($withWebSearch) {
            $parameters['instructions'] = $system."\n\nRespond with a single valid JSON object only, no markdown fences or extra text.";
            $parameters['tools'] = [['type' => 'web_search']];
            $parameters['max_tool_calls'] = 1;
        } else {
            $parameters['text'] = ['format' => ['type' => 'json_object']];
        }

        try {
            $response = OpenAI::responses()->create($parameters);
        } catch (\Throwable $e) {
            Log::error('OpenAI responses request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('OpenAI request failed: '.$e->getMessage(), previous: $e);
        }

        return $this->parseResponsesJson($response);
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     web_search_calls: int
     * }
     */
    private function parseResponsesJson(CreateResponse $response): array
    {
        $content = trim($response->outputText ?? '');

        if ($content === '') {
            throw new RuntimeException('OpenAI returned empty content.');
        }

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $parsed = json_decode(substr($content, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return [
            'parsed' => $parsed,
            'input_tokens' => $response->usage?->inputTokens ?? 0,
            'output_tokens' => $response->usage?->outputTokens ?? 0,
            'total_tokens' => $response->usage?->totalTokens ?? 0,
            'web_search_calls' => $this->countWebSearchCalls($response->output),
        ];
    }

    /**
     * @param  array<int, mixed>  $output
     */
    private function countWebSearchCalls(array $output): int
    {
        $count = 0;

        foreach ($output as $item) {
            if ($item instanceof OutputWebSearchToolCall && $item->action?->type === 'search') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<mixed>  $variants
     * @return list<array{content: string, virality_score: int, estimated_reach: string}>
     */
    private function normalizeVariants(array $variants): array
    {
        $normalized = [];

        foreach ($variants as $variant) {
            if (! is_array($variant) || empty($variant['content'])) {
                continue;
            }

            $score = max(0, min(100, (int) ($variant['virality_score'] ?? 70)));
            $reach = $variant['estimated_reach'] ?? 'medium';
            if (! in_array($reach, ['low', 'medium', 'high'], true)) {
                $reach = 'medium';
            }

            $angle = trim((string) ($variant['angle'] ?? ''));

            $normalized[] = [
                'content' => trim((string) $variant['content']),
                'angle' => $angle === '' ? null : mb_substr($angle, 0, 80),
                'virality_score' => $score,
                'estimated_reach' => $reach,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<mixed>  $drafts
     * @return list<array{content: string, tone: string}>
     */
    private function normalizeReplyDrafts(array $drafts, string $tone): array
    {
        $normalized = [];

        foreach ($drafts as $draft) {
            if (! is_array($draft) || empty($draft['content'])) {
                continue;
            }

            $normalized[] = [
                'content' => trim((string) $draft['content']),
                'tone' => (string) ($draft['tone'] ?? $tone),
            ];
        }

        return $normalized;
    }
}
