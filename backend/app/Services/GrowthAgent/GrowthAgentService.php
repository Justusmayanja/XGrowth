<?php

namespace App\Services\GrowthAgent;

use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\Post;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\AICore\AICoreService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\TrendEngine\TrendEngineService;
use App\Services\XApi\XApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GrowthAgentService
{
    public function __construct(
        private readonly SafetyEngineService $safetyEngine,
        private readonly OpportunityScorerService $opportunityScorer,
        private readonly GrowthBrainService $growthBrain,
        private readonly AICoreService $aiCore,
        private readonly PlatformSettingsService $settings,
        private readonly XApiService $xApi,
        private readonly TrendEngineService $trends,
    ) {}

    private bool $draftedThisRun = false;

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
        $this->draftedThisRun = false;

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

        if ($this->canUnattendedPost($profile)) {
            $queued = $this->publishQueuedDraft($user, $profile);
            if ($queued !== null) {
                return $queued;
            }
        }

        $prefilterLimit = max(1, $this->settings->getInt('growth_brain_prefilter_limit', 5));
        $fromNiche = $this->trends->rankedInNiche($user);
        $fromAccount = $this->trends->rankedFromAccount($user);
        if (is_array($fromNiche) && $fromNiche !== []) {
            $ranked = array_slice($fromNiche, 0, $prefilterLimit);
        } elseif (is_array($fromAccount) && $fromAccount !== []) {
            $ranked = array_slice($fromAccount, 0, $prefilterLimit);
        } else {
            try {
                $this->trends->ensureCurrentTrends($user);
            } catch (Throwable $e) {
                Log::warning('Could not refresh live conversations', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $ranked = $this->opportunityScorer->rankedForProfile($profile, $prefilterLimit);
        }

        if ($ranked === []) {
            if ($this->shouldFillSilence($user, $profile)) {
                return $this->evaluateOriginalFallback(
                    $user,
                    $profile,
                    app(\App\Services\AICore\AiContextBuilder::class)->contentMixDigest($user),
                );
            }

            $this->logDecision($user, 'WAIT', null, 0, 'No trends available to evaluate yet', []);

            return 'WAIT';
        }

        if ($this->growthBrain->isEnabled()) {
            try {
                return $this->finish($user, $profile, $this->evaluateWithBrain($user, $profile, $ranked));
            } catch (RuntimeException $e) {
                Log::warning('Growth brain fallback to rules', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->finish($user, $profile, $this->evaluateWithRules($user, $profile, $ranked));
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

        if ($decision === 'POST') {
            return $this->actOnPostOpportunity(
                $user,
                $profile,
                $trend,
                $evaluation['content_angle'],
                $evaluation['content_source'],
                $score,
                $reason,
                $context,
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

        return $this->logDecision($user, $decision, null, $score, $reason, $context);
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
            return $this->actOnPostOpportunity(
                $user,
                $profile,
                $trend,
                $this->bridgeAngle($trend),
                'trend',
                $score,
                "People are on \"{$trend->name}\" ({$score}/100). Draft this account's side of it",
                $context,
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
        $context['content_angle'] = $ideas[0] ?? 'Join what people are talking about, from this account\'s side';

        return $this->actOnPostOpportunity(
            $user,
            $profile,
            null,
            $context['content_angle'],
            'original',
            $score,
            'Quiet stretch in your niche — original draft checked for virality',
            $context,
        );
    }

    private function finish(User $user, AccountProfile $profile, string $decision): string
    {
        if ($decision === 'POST' || $this->draftedThisRun || ! $this->shouldFillSilence($user, $profile)) {
            return $decision;
        }

        return $this->evaluateOriginalFallback(
            $user,
            $profile,
            app(\App\Services\AICore\AiContextBuilder::class)->contentMixDigest($user),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function actOnPostOpportunity(
        User $user,
        AccountProfile $profile,
        ?\App\Models\Trend $trend,
        ?string $contentAngle,
        string $contentSource,
        float $score,
        string $reason,
        array $context,
    ): string {
        $draft = $this->maybeGenerateDraft($user, $profile, $trend, $contentAngle, $contentSource);

        if (! $this->canUnattendedPost($profile)) {
            if ($draft) {
                $context['draft_post_id'] = $draft->id;
                $context['draft_preview'] = mb_substr($draft->content, 0, 160);
            }

            return $this->logDecision(
                $user,
                'WAIT',
                'suggest_draft',
                $score,
                $profile->auto_post_enabled
                    ? $reason.' — review the draft before it goes live'
                    : $reason.' — posting is off, so this stays a draft',
                $context
            );
        }

        return $this->publishOrHold($user, $profile, $draft, $score, $reason, $context);
    }

    private function publishQueuedDraft(User $user, AccountProfile $profile): ?string
    {
        if (! $this->safetyEngine->canAct($user, 'post')) {
            return null;
        }

        $draft = Post::query()
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->whereIn('content_type', ['tweet', 'thread'])
            ->where('virality_predicted', '>=', $this->viralityFloor($profile))
            ->latest('id')
            ->first();

        if (! $draft) {
            return null;
        }

        return $this->publishOrHold(
            $user,
            $profile,
            $draft,
            (float) ($draft->virality_predicted ?? 0),
            'A drafted post cleared the virality check for your niche',
            [
                'engine' => 'autopublish',
                'content_source' => $draft->trend_id ? 'trend' : 'original',
                'draft_post_id' => $draft->id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function publishOrHold(
        User $user,
        AccountProfile $profile,
        ?Post $draft,
        float $score,
        string $reason,
        array $context,
    ): string {
        if (! $draft) {
            return $this->logDecision(
                $user,
                'WAIT',
                'draft_failed',
                $score,
                $reason.' — could not draft a post this round',
                $context
            );
        }

        $context['draft_post_id'] = $draft->id;
        $context['draft_preview'] = mb_substr($draft->content, 0, 160);
        $context['virality_predicted'] = $draft->virality_predicted;

        $floor = $this->viralityFloor($profile);
        $virality = (float) ($draft->virality_predicted ?? 0);

        if ($virality < $floor) {
            return $this->logDecision(
                $user,
                'WAIT',
                'held_low_virality',
                $score,
                $reason." — held the draft (virality {$virality}/100, needs {$floor} in this niche)",
                $context
            );
        }

        $account = $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return $this->logDecision(
                $user,
                'WAIT',
                'draft_prepared',
                $score,
                $reason.' — connect X and this draft can go live',
                $context
            );
        }

        if (! $this->safetyEngine->canAct($user, 'post')) {
            return $this->logDecision(
                $user,
                'WAIT',
                'draft_prepared',
                $score,
                'Daily post limit reached — the draft is saved',
                $context
            );
        }

        try {
            $platformPostId = $this->xApi->publishText($account, $draft->content, $draft->content_type);
        } catch (RuntimeException $e) {
            Log::warning('Autopublish failed', [
                'user_id' => $user->id,
                'post_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);

            return $this->logDecision(
                $user,
                'WAIT',
                'publish_failed',
                $score,
                'Could not publish this draft. It will be tried again.',
                $context
            );
        }

        $draft->update([
            'status' => 'posted',
            'platform_post_id' => $platformPostId,
            'connected_account_id' => $account->id,
            'posted_at' => now(),
        ]);
        $this->safetyEngine->recordAction($user, 'post');

        return $this->logDecision(
            $user,
            'POST',
            'posted',
            $score,
            $reason.' — published automatically',
            $context
        );
    }

    private function canUnattendedPost(AccountProfile $profile): bool
    {
        return ! $profile->engine_paused
            && (int) ($profile->automation_level ?? 1) >= 2
            && (bool) $profile->auto_post_enabled;
    }

    private function viralityFloor(AccountProfile $profile): int
    {
        return (int) ($profile->automation_level ?? 1) >= 3 ? 60 : 75;
    }

    private function shouldFillSilence(User $user, AccountProfile $profile): bool
    {
        if ((int) ($profile->automation_level ?? 1) < 3 || ! $this->canUnattendedPost($profile)) {
            return false;
        }

        if (! $this->safetyEngine->canAct($user, 'post')) {
            return false;
        }

        $minutes = max(15, (int) ($profile->max_silence_minutes ?? 60));
        $lastPosted = $user->posts()->where('status', 'posted')->latest('posted_at')->value('posted_at');

        if (! $lastPosted) {
            return true;
        }

        return Carbon::parse($lastPosted)->lte(now()->subMinutes($minutes));
    }

    private function maybeGenerateDraft(
        User $user,
        AccountProfile $profile,
        ?\App\Models\Trend $trend,
        ?string $contentAngle,
        string $contentSource = 'trend',
    ): ?Post {
        $this->draftedThisRun = true;

        if (! $this->aiCore->isConfigured()) {
            return null;
        }

        $isOriginal = $contentSource === 'original' || $trend === null;
        $fullAuto = (int) ($profile->automation_level ?? 1) >= 3;

        try {
            $metadata = is_array($trend?->metadata) ? $trend->metadata : [];
            $liveName = in_array($metadata['source'] ?? '', ['x', 'live'], true) ? $trend->name : null;

            $params = [
                'user' => $user,
                'topic' => $liveName ?? 'a conversation people are having today',
                'conversation' => $liveName,
                'join_live' => true,
                'tone' => $profile->primaryTone(),
                'niche' => $profile->resolvedNiche(),
                'goal' => str_replace('_', ' ', $profile->primaryGoal()),
                'content_type' => 'tweet',
                'audience' => $profile->audience,
                'description' => $profile->description,
                'stay_in_niche' => true,
                'planner_hint' => $this->safeHint($contentAngle, $profile),
                'variant_count' => 1,
                'recent_posts' => $user->posts()->latest()->limit(5)->pluck('content')->all(),
                'use_web_search' => $liveName === null && ($fullAuto || (! $isOriginal && $this->shouldUseWebSearchForTrend($trend))),
                'score_virality' => true,
                'content_source' => $isOriginal ? 'original' : 'trend',
            ];

            try {
                $result = $this->aiCore->generateContentVariants($params);
            } catch (RuntimeException $e) {
                if (! $params['use_web_search']) {
                    throw $e;
                }

                Log::warning('Autopilot web search failed, drafting without it', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $params['use_web_search'] = false;
                $result = $this->aiCore->generateContentVariants($params);
            }

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

    private function bridgeAngle(\App\Models\Trend $trend): string
    {
        $metadata = is_array($trend->metadata) ? $trend->metadata : [];
        $live = in_array($metadata['source'] ?? '', ['x', 'live'], true);

        return $live
            ? 'Answer "'.$trend->name.'" from this account\'s side'
            : 'Join what people are talking about today, from this account\'s side';
    }

    private function safeHint(?string $hint, AccountProfile $profile): ?string
    {
        $hint = trim((string) $hint);
        if ($hint === '') {
            return null;
        }

        $lower = mb_strtolower($hint);
        foreach ([$profile->resolvedNiche(), (string) $profile->description, (string) $profile->audience] as $note) {
            $note = trim($note);
            if (mb_strlen($note) >= 8 && str_contains($lower, mb_strtolower($note))) {
                return null;
            }
        }

        return mb_substr($hint, 0, 140);
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
