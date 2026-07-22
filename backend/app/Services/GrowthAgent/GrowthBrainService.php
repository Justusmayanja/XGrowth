<?php

namespace App\Services\GrowthAgent;

use App\Models\AccountProfile;
use App\Models\AiRule;
use App\Models\Trend;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\AICore\AiContextBuilder;
use App\Services\AICore\AiUsageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class GrowthBrainService
{
    public function __construct(
        private readonly AiContextBuilder $context,
        private readonly AiUsageService $usage,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function isEnabled(): bool
    {
        if (! $this->settings->getBool('growth_brain_enabled', true)) {
            return false;
        }

        $key = config('openai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @param  list<array{trend: Trend, opportunity_score: float}>  $rankedTrends
     * @return array{
     *     decision: string,
     *     opportunity_score: float,
     *     trend: Trend|null,
     *     content_source: string,
     *     reason: string,
     *     content_angle: string|null,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int
     * }
     */
    public function evaluate(
        User $user,
        AccountProfile $profile,
        array $rankedTrends,
        array $safetyStatus,
    ): array {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Growth brain is not enabled.');
        }

        $this->usage->assertCanGenerate($user);

        $trends = array_map(fn (array $row) => $row['trend'], $rankedTrends);
        $payload = [
            'profile' => $this->context->profileDigest($profile),
            'safety' => $this->context->safetyDigest($safetyStatus['actions'] ?? []),
            'mix' => $this->context->contentMixDigest($user),
            'original_ideas' => $this->context->originalIdeasDigest($profile),
            'trends' => $this->context->trendDigest($trends),
            'last' => $this->context->lastDecisionDigest($user),
        ];

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->context->encode($payload);

        try {
            $response = OpenAI::chat()->create([
                'model' => $this->settings->getOpenAiModel(),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.4,
                'max_tokens' => max(100, $this->settings->getInt('growth_brain_max_output_tokens', 400)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Growth brain evaluation failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            throw new RuntimeException('Growth brain evaluation failed: '.$e->getMessage(), previous: $e);
        }

        $content = $response->choices[0]->message->content ?? '';
        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            throw new RuntimeException('Growth brain returned invalid JSON.');
        }

        $decision = strtoupper((string) ($parsed['decision'] ?? 'WAIT'));
        if (! in_array($decision, ['POST', 'WAIT', 'ENGAGE', 'IGNORE'], true)) {
            $decision = 'WAIT';
        }

        $trendId = isset($parsed['trend_id']) ? (int) $parsed['trend_id'] : null;
        $contentSource = strtolower((string) ($parsed['content_source'] ?? 'trend'));
        if (! in_array($contentSource, ['trend', 'original'], true)) {
            $contentSource = 'trend';
        }

        $mix = $this->context->contentMixDigest($user);
        if ($mix['prefer'] === 'original') {
            $contentSource = 'original';
            $trendId = null;
        } elseif ($mix['prefer'] === 'trend') {
            $contentSource = 'trend';
        }

        $trend = ($contentSource === 'trend' && $trendId)
            ? collect($trends)->firstWhere('id', $trendId)
            : null;

        if ($contentSource === 'trend' && ! $trend && $trends !== []) {
            $trend = $trends[0];
        }

        $score = max(0, min(100, (float) ($parsed['opportunity_score'] ?? 0)));
        $reason = trim((string) ($parsed['reason'] ?? 'AI evaluation completed.'));
        $contentAngle = isset($parsed['content_angle']) ? trim((string) $parsed['content_angle']) : null;

        $inputTokens = (int) ($response->usage->promptTokens ?? 0);
        $outputTokens = (int) ($response->usage->completionTokens ?? 0);

        $this->usage->record($user, [
            'feature' => 'growth_evaluation',
            'model' => $this->settings->getOpenAiModel(),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => (int) ($response->usage->totalTokens ?? ($inputTokens + $outputTokens)),
            'web_search_calls' => 0,
            'used_web_search' => false,
            'metadata' => [
                'decision' => $decision,
                'trend_id' => $trend?->id,
                'content_source' => $contentSource,
                'prefilter_count' => count($trends),
            ],
        ]);

        Cache::put($this->cooldownKey($user->id), now()->timestamp, now()->addDay());

        return [
            'decision' => $decision,
            'opportunity_score' => $score,
            'trend' => $trend,
            'content_source' => $contentSource,
            'reason' => $reason,
            'content_angle' => $contentAngle,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => (int) ($response->usage->totalTokens ?? ($inputTokens + $outputTokens)),
        ];
    }

    public function shouldSkipScheduledEvaluation(int $userId): bool
    {
        $cooldown = max(0, $this->settings->getInt('growth_eval_cooldown_minutes', 30));
        if ($cooldown <= 0) {
            return false;
        }

        $last = Cache::get($this->cooldownKey($userId));
        if (! is_numeric($last)) {
            return false;
        }

        return now()->diffInMinutes(\Illuminate\Support\Carbon::createFromTimestamp((int) $last)) < $cooldown;
    }

    private function buildSystemPrompt(): string
    {
        $adminRules = AiRule::systemPromptPrefix('growth_decisions', 'content_mix');

        $role = <<<'PROMPT'
You are the Growth Brain for an X (Twitter) growth platform. You receive compact JSON only.

Tasks:
1. Choose content_source: "trend" (timely, tied to a trend) OR "original" (evergreen, from original_ideas — not trend news).
2. Score the opportunity (0-100) for your chosen angle.
3. Decide: POST, ENGAGE, WAIT, or IGNORE.
4. Short reason (max 2 sentences).
5. If POST or ENGAGE: content_angle (max 120 chars). For original, pick/adapt from original_ideas.

Content mix (mandatory):
- Obey mix.max_trend_pct — trend-based posts must stay at or below that share.
- If mix.prefer is "original", you MUST use content_source "original" and trend_id null.
- If mix.prefer is "trend", favor timely trend content when a strong trend fits the niche.
- If mix.prefer is "balanced", choose the better opportunity between a trend and an original idea.
- Original content builds authority; trend content captures moments — both matter.

Other rules:
- Respect safety.*_left — if post_left is 0, never decide POST.
- Respect profile.automation and auto_post.
- Do not invent trends or metrics not in the input.

Output ONLY JSON:
{"decision":"POST|WAIT|ENGAGE|IGNORE","content_source":"trend|original","opportunity_score":0-100,"trend_id":number|null,"reason":"...","content_angle":"..."|null}
PROMPT;

        if ($adminRules === '') {
            return $role;
        }

        return $adminRules."\n\n---\n\n".$role;
    }

    private function cooldownKey(int $userId): string
    {
        return "growth_brain:last_eval:{$userId}";
    }
}
