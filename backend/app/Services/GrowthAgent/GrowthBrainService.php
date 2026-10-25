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

        $candidate = $trendId
            ? collect($trends)->firstWhere('id', $trendId)
            : null;
        if (! $candidate && $trends !== []) {
            $candidate = $trends[0];
        }

        $mix = $this->context->contentMixDigest($user);
        if ($mix['prefer'] === 'original' && ! $this->momentIsLive($candidate)) {
            $contentSource = 'original';
            $candidate = null;
        } elseif ($mix['prefer'] === 'trend' || $this->momentIsLive($candidate)) {
            $contentSource = 'trend';
        }

        $trend = $contentSource === 'trend' ? $candidate : null;

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

    public function forgetScheduledCooldown(int $userId): void
    {
        Cache::forget($this->cooldownKey($userId));
    }

    public function noteHeat(int $userId, bool $hot): void
    {
        Cache::put($this->heatKey($userId), $hot ? 'hot' : 'quiet', now()->addHours(2));
    }

    public function shouldSkipScheduledEvaluation(int $userId): bool
    {
        $last = Cache::get($this->cooldownKey($userId));
        if (! is_numeric($last)) {
            return false;
        }

        $minutes = (int) abs(now()->diffInMinutes(\Illuminate\Support\Carbon::createFromTimestamp((int) $last)));
        if (Cache::get($this->heatKey($userId)) === 'hot') {
            return $minutes < 4;
        }

        $cooldown = max(0, $this->settings->getInt('growth_eval_cooldown_minutes', 30));
        if ($cooldown <= 0) {
            return false;
        }

        return $minutes < $cooldown;
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

Content mix:
- A live moment in this niche overrides the mix. Use content_source "trend" only for a trend that is about this niche.
- If nothing in the niche is live and mix.prefer is "original", use content_source "original". The original post stays inside the niche.
- content_angle is this account's opinion. It is not a restatement of the description and not a summary of the trend.

Other rules:
- Respect safety.*_left — if post_left is 0, never decide POST.
- This is not a schedule. POST when a new moment is actually happening inside this niche: a goal, a price jump in this niche, a decision this niche would post, a large account in this niche saying something new.
- A new beat of an event already posted about can be POST. The same sentence cannot.
- Stay in the niche. IGNORE a war, a politician, a celebrity, or any worldwide trend this niche would not post. A high follower count does not make an outside topic relevant.
- A supplied trend is already from this account's home timeline. It still has to fit the description and the people the account is for. IGNORE it when those people would not care. The aim (grow followers, promote the business, or whatever the profile says) decides the point of the post, not a new subject.
- A trend with life set is a second loyalty that keeps appearing on this account's own timeline, such as a bag seller who also follows a club. That loyalty is part of this account. POST it as that person, not as an advert for the shop. A war or a politician is still IGNORE.
- Another match can be POST only when it is already one of the supplied trends and it is the same sport. A price or a rule can be POST only when this niche is money and that item is already one of the supplied trends.
- content_angle is the opinion, not a summary of the news and not a plan.
- If a trend is marked adjacent, only choose it when someone in this niche would actually care.
- If minutes on a trend are over 180, it is old. Do not treat it as breaking.
- A name in a post is not a current job. Do not put a coach, a rate, or a score into content_angle unless the input states it as happening now.
- A large account posting does not mean POST. Skip some. Answer the ones this account would actually care about.
- A quiet stretch is normal. If last.mins is past about 180, one post can end the stretch. Do not turn that into a repeating clock.
- If nothing new is loud and the stretch is still short, WAIT.
- If profile.automation is below 2, POST means the moment is worth a draft. The app will not publish it.
- Do not invent trends or metrics not in the input.

Output ONLY JSON:
{"decision":"POST|WAIT|ENGAGE|IGNORE","content_source":"trend|original","opportunity_score":0-100,"trend_id":number|null,"reason":"...","content_angle":"..."|null}
PROMPT;

        if ($adminRules === '') {
            return $role;
        }

        return $adminRules."\n\n---\n\n".$role;
    }

    private function momentIsLive(?Trend $trend): bool
    {
        if (! $trend) {
            return false;
        }

        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        if (! empty($meta['live']) && empty($meta['adjacent'])) {
            return true;
        }

        $minutes = $meta['minutes_ago'] ?? null;

        return is_numeric($minutes) && (int) $minutes <= 90;
    }

    private function cooldownKey(int $userId): string
    {
        return "growth_brain:last_eval:{$userId}";
    }

    private function heatKey(int $userId): string
    {
        return "growth:heat:{$userId}";
    }
}
