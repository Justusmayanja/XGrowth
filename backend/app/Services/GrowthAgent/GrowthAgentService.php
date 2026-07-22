<?php

namespace App\Services\GrowthAgent;

use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\Post;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\AICore\AICoreService;
use App\Services\TrendEngine\OpportunityScorerService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GrowthAgentService
{
    public function __construct(
        private readonly SafetyEngineService $safetyEngine,
        private readonly OpportunityScorerService $opportunityScorer,
        private readonly GrowthBrainService $growthBrain,
        private readonly AICoreService $aiCore,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function evaluateAllUsers(): int
    {
        $count = 0;

        User::query()
            ->where('is_blocked', false)
            ->whereHas('accountProfile', fn ($q) => $q
                ->where('onboarding_completed', true)
                ->where('engine_paused', false))
            ->each(function (User $user) use (&$count) {
                if ($this->growthBrain->isEnabled() && $this->growthBrain->shouldSkipScheduledEvaluation($user->id)) {
                    return;
                }

                $this->evaluateOpportunities($user->id);
                $count++;
            });

        return $count;
    }

    public function evaluateOpportunities(int $userId): ?string
    {
        $user = User::with('accountProfile')->find($userId);

        if (! $user?->accountProfile?->onboarding_completed || $user->is_blocked) {
            return null;
        }

        $profile = $user->accountProfile;

        if ($profile->engine_paused) {
            return $this->logDecision(
                $user,
                'WAIT',
                null,
                0,
                'Growth Engine is paused — turn it back on in Settings when ready',
                []
            );
        }

        $prefilterLimit = max(1, $this->settings->getInt('growth_brain_prefilter_limit', 5));
        $ranked = $this->opportunityScorer->rankedForProfile($profile, $prefilterLimit);

        if ($ranked === []) {
            $this->logDecision($user, 'WAIT', null, 0, 'No trends available to evaluate yet', []);

            return 'WAIT';
        }

        if ($this->growthBrain->isEnabled()) {
            try {
                return $this->evaluateWithBrain($user, $profile, $ranked);
            } catch (RuntimeException $e) {
                Log::warning('Growth brain fallback to rules', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->evaluateWithRules($user, $profile, $ranked);
    }

    /**
     * @param  list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion: string}>  $ranked
     */
    private function evaluateWithBrain(User $user, AccountProfile $profile, array $ranked): string
    {
        $safetyStatus = $this->safetyEngine->status($user);
        $evaluation = $this->growthBrain->evaluate($user, $profile, $ranked, $safetyStatus);

        /** @var \App\Models\Trend|null $trend */
        $trend = $evaluation['trend'];
        $decision = $evaluation['decision'];
        $score = $evaluation['opportunity_score'];
        $reason = $evaluation['reason'];

        $context = [
            'engine' => 'growth_brain',
            'content_source' => $evaluation['content_source'],
            'trend_id' => $trend?->id,
            'trend_name' => $trend?->name,
            'content_angle' => $evaluation['content_angle'],
            'tokens' => [
                'input' => $evaluation['input_tokens'],
                'output' => $evaluation['output_tokens'],
                'total' => $evaluation['total_tokens'],
            ],
        ];

        if ($decision === 'POST' && ! $this->safetyEngine->canAct($user, 'post')) {
            return $this->logDecision(
                $user,
                'WAIT',
                null,
                $score,
                'AI recommended POST but daily post limit reached — pacing to protect your account',
                $context
            );
        }

        $action = null;
        if ($decision === 'POST') {
            $automation = (int) ($profile->automation_level ?? 1);
            $canAutoPost = $automation >= 2 && $profile->auto_post_enabled;

            if ($canAutoPost) {
                $draft = $this->maybeGenerateDraft(
                    $user,
                    $profile,
                    $trend,
                    $evaluation['content_angle'],
                    $evaluation['content_source'],
                );
                if ($draft) {
                    $context['draft_post_id'] = $draft->id;
                    $context['draft_preview'] = mb_substr($draft->content, 0, 160);
                }

                return $this->logDecision(
                    $user,
                    'POST',
                    'draft_prepared',
                    $score,
                    $reason,
                    $context
                );
            }

            $this->maybeGenerateDraft(
                $user,
                $profile,
                $trend,
                $evaluation['content_angle'],
                $evaluation['content_source'],
            );

            return $this->logDecision(
                $user,
                'WAIT',
                'suggest_draft',
                $score,
                $profile->auto_post_enabled
                    ? $reason.' — review draft in Content Studio'
                    : $reason.' — auto-posting is off; review draft in Content Studio',
                $context
            );
        }

        if ($decision === 'ENGAGE' && ! $profile->auto_engage_enabled) {
            return $this->logDecision(
                $user,
                'WAIT',
                'suggest_engage',
                $score,
                $reason.' — auto-engage is off',
                $context
            );
        }

        return $this->logDecision($user, $decision, $action, $score, $reason, $context);
    }

    /**
     * @param  list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion: string}>  $ranked
     */
    private function evaluateWithRules(User $user, AccountProfile $profile, array $ranked): string
    {
        $mix = app(\App\Services\AICore\AiContextBuilder::class)->contentMixDigest($user);

        if ($mix['prefer'] === 'original') {
            return $this->evaluateOriginalFallback($user, $profile, $mix);
        }

        $top = $ranked[0];
        $trend = $top['trend'];
        $score = $top['opportunity_score'];
        $automation = $profile->automation_level ?? 1;

        $context = [
            'engine' => 'rules_fallback',
            'trend_id' => $trend->id,
            'trend_name' => $trend->name,
            'competition' => $trend->competition,
            'growth_rate' => $trend->growth_rate,
        ];

        if (! $this->safetyEngine->canAct($user, 'post')) {
            return $this->logDecision(
                $user,
                'WAIT',
                null,
                $score,
                'Daily post limit reached — pacing activity to protect your account',
                $context
            );
        }

        $postThreshold = $this->settings->getInt('rules_post_threshold', 82);
        $engageThreshold = $this->settings->getInt('rules_engage_threshold', 68);

        if ($score >= $postThreshold) {
            $canAutoPost = $automation >= 2 && $profile->auto_post_enabled;

            if ($canAutoPost) {
                return $this->logDecision(
                    $user,
                    'POST',
                    'draft_prepared',
                    $score,
                    "High opportunity ({$score}/100) on \"{$trend->name}\" — draft ready for your niche",
                    $context
                );
            }

            return $this->logDecision(
                $user,
                'WAIT',
                'suggest_draft',
                $score,
                $automation === 0 || ! $profile->auto_post_enabled
                    ? "Strong opportunity on \"{$trend->name}\" — auto-posting is off; review draft in Content Studio"
                    : "Strong opportunity on \"{$trend->name}\" — review suggested draft in Content Studio",
                $context
            );
        }

        if ($score >= $engageThreshold && $profile->auto_engage_enabled) {
            return $this->logDecision(
                $user,
                'ENGAGE',
                'monitor_conversation',
                $score,
                "Rising conversation on \"{$trend->name}\" — good moment to reply or draft",
                $context
            );
        }

        return $this->logDecision(
            $user,
            'WAIT',
            null,
            $score,
            "Opportunity score {$score}/100 on \"{$trend->name}\" — waiting for a stronger moment",
            $context
        );
    }

    /**
     * @param  array<string, mixed>  $mix
     */
    private function evaluateOriginalFallback(User $user, AccountProfile $profile, array $mix): string
    {
        $score = 78.0;
        $context = [
            'engine' => 'rules_fallback',
            'content_source' => 'original',
            'mix' => $mix,
        ];

        if (! $this->safetyEngine->canAct($user, 'post')) {
            return $this->logDecision(
                $user,
                'WAIT',
                null,
                $score,
                'Daily post limit reached — original content draft deferred',
                $context
            );
        }

        $ideas = app(\App\Services\AICore\AiContextBuilder::class)->originalIdeasDigest($profile);
        $context['content_angle'] = $ideas[0] ?? 'Evergreen insight for your niche';

        $this->maybeGenerateDraft($user, $profile, null, $context['content_angle'], 'original');

        return $this->logDecision(
            $user,
            'WAIT',
            'suggest_draft',
            $score,
            'Content mix favors original posts — evergreen draft ready in Content Studio',
            $context
        );
    }

    private function maybeGenerateDraft(
        User $user,
        AccountProfile $profile,
        ?\App\Models\Trend $trend,
        ?string $contentAngle,
        string $contentSource = 'trend',
    ): ?Post {
        if (! $this->aiCore->isConfigured()) {
            return null;
        }

        $isOriginal = $contentSource === 'original' || $trend === null;

        try {
            if ($isOriginal) {
                $topic = $contentAngle
                    ?: app(\App\Services\AICore\AiContextBuilder::class)
                        ->originalIdeasDigest($profile)[0];
            } else {
                $topic = $contentAngle
                    ? "{$trend->name} — {$contentAngle}"
                    : $trend->name;
            }

            $result = $this->aiCore->generateContentVariants([
                'user' => $user,
                'topic' => mb_substr($topic, 0, 500),
                'tone' => $profile->primaryTone(),
                'niche' => $profile->resolvedNiche(),
                'goal' => str_replace('_', ' ', $profile->primaryGoal()),
                'content_type' => 'tweet',
                'audience' => $profile->audience,
                'description' => $profile->description,
                'variant_count' => 1,
                'use_web_search' => ! $isOriginal && $this->shouldUseWebSearchForTrend($trend),
                'content_source' => $isOriginal ? 'original' : 'trend',
            ]);

            $variant = $result['variants'][0] ?? null;
            if (! $variant) {
                return null;
            }

            return Post::create([
                'user_id' => $user->id,
                'trend_id' => $isOriginal ? null : $trend?->id,
                'content' => $variant['content'],
                'content_type' => 'tweet',
                'status' => 'draft',
                'virality_predicted' => $variant['virality_score'],
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Autopilot draft generation skipped', [
                'user_id' => $user->id,
                'trend_id' => $trend?->id,
                'content_source' => $contentSource,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function shouldUseWebSearchForTrend(?\App\Models\Trend $trend): bool
    {
        if (! $trend || ! $this->settings->getBool('web_search_enabled', true)) {
            return false;
        }

        $keywords = ['breaking', 'news', 'deadline', 'transfer', 'launch', 'live', 'today'];
        $name = strtolower($trend->name);

        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                return true;
            }
        }

        return ($trend->growth_rate ?? 0) >= 25;
    }

    /**
     * Decides POST / WAIT / ENGAGE / IGNORE for one account.
     */
    public function evaluate(User $user, AccountProfile $profile, float $opportunityScore): string
    {
        if (! $this->safetyEngine->canAct($user, 'post')) {
            $this->logDecision($user, 'WAIT', null, $opportunityScore, 'Safety limits reached', []);

            return 'WAIT';
        }

        $postThreshold = $this->settings->getInt('rules_post_threshold', 82);
        $engageThreshold = $this->settings->getInt('rules_engage_threshold', 68);

        if ($opportunityScore >= $postThreshold) {
            $this->logDecision($user, 'POST', 'evaluate_opportunity', $opportunityScore, 'High opportunity score', []);

            return 'POST';
        }

        if ($opportunityScore >= $engageThreshold) {
            $this->logDecision($user, 'ENGAGE', null, $opportunityScore, 'Moderate opportunity — engagement recommended', []);

            return 'ENGAGE';
        }

        $this->logDecision($user, 'WAIT', null, $opportunityScore, 'Opportunity below threshold', []);

        return 'WAIT';
    }

    private function logDecision(
        User $user,
        string $decision,
        ?string $action,
        ?float $score,
        string $reason,
        array $context
    ): string {
        AiDecision::create([
            'user_id' => $user->id,
            'decision' => $decision,
            'action_taken' => $action,
            'opportunity_score' => $score,
            'reason' => $reason,
            'context' => $context,
        ]);

        return $decision;
    }
}
