<?php

namespace App\Services\AICore;

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
        $goal = $params['goal'] ?? 'grow on X';
        $contentType = $params['content_type'] ?? 'tweet';
        $variantCount = max(1, min(3, (int) ($params['variant_count'] ?? 3)));

        $systemPrompt = $this->buildSystemPrompt(
            'content_generation',
            'You are an expert X (Twitter) content strategist. Generate engaging post drafts tailored to the user\'s niche and voice.',
            includeContentMix: true,
        );

        $userPrompt = "Generate exactly {$variantCount} distinct {$contentType} draft options about: {$topic}\n\n";
        $userPrompt .= "User profile:\n";
        $userPrompt .= "- Niche: {$niche}\n";
        $userPrompt .= "- Tone: {$tone}\n";
        $userPrompt .= "- Goal: {$goal}\n";

        if (! empty($params['audience'])) {
            $userPrompt .= '- Target audience: '.$params['audience']."\n";
        }

        if (! empty($params['description'])) {
            $userPrompt .= '- About them: '.$params['description']."\n";
        }

        if ($useWebSearch) {
            $userPrompt .= "\nUse web search to verify current facts, names, dates, and news about this topic before drafting.\n";
        } elseif (($params['content_source'] ?? '') === 'original') {
            $userPrompt .= "\nThis is ORIGINAL evergreen content — do not reference breaking news or current trends.\n";
        }

        $userPrompt .= <<<'PROMPT'

Return JSON:
{
  "variants": [
    {
      "content": "the post text",
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
                'content_type' => $contentType,
            ],
        ]);

        $note = "AI drafts for {$niche} · {$tone} tone";
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
    private function chatJson(string $system, string $user): array
    {
        try {
            $response = OpenAI::chat()->create([
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.8,
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
            'text' => [
                'format' => ['type' => 'json_object'],
            ],
            'temperature' => 0.8,
        ];

        if ($withWebSearch) {
            $parameters['tools'] = [['type' => 'web_search']];
            $parameters['max_tool_calls'] = 1;
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

            $normalized[] = [
                'content' => trim((string) $variant['content']),
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
