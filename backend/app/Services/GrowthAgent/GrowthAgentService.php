<?php

namespace App\Services\GrowthAgent;

use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\Post;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\AICore\AICoreService;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\TrendEngine\TrendEngineService;
use App\Services\XApi\XApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        private readonly ApiSpendService $budget,
        private readonly XGoalPlan $goals,
    ) {}

    private bool $draftedThisRun = false;

    private bool $postedThisRun = false;

    /** @var list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion?: string}> */
    private array $momentsThisRun = [];

    /** @var list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion?: string}> */
    private array $shareMoments = [];

    private ?\App\Models\ConnectedAccount $accountThisRun = null;

    private int $xAccountCount = 1;

    private bool $sawHeat = false;

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
            $this->accountThisRun = null;

            return $this->logDecision(
                $user,
                'WAIT',
                null,
                0,
                'Growth Engine is paused — turn it back on in Settings when ready',
                []
            );
        }

        $accounts = $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
        $this->xAccountCount = max(1, $accounts->count());
        $this->sawHeat = false;

        if ($accounts->count() <= 1) {
            $this->accountThisRun = $accounts->first();
            $decision = $this->runOnce($user, $profile);
        } else {
            $decision = null;
            foreach ($accounts as $account) {
                $this->accountThisRun = $account;
                $decision = $this->runOnce($user, $this->withVoice($profile, $account));
            }
        }

        $this->growthBrain->noteHeat($user->id, $this->sawHeat);

        return $decision;
    }

    private function runOnce(User $user, AccountProfile $profile): string
    {
        $this->draftedThisRun = false;
        $this->postedThisRun = false;
        $this->momentsThisRun = [];
        $this->shareMoments = [];

        $prefilterLimit = max(1, $this->settings->getInt('growth_brain_prefilter_limit', 5));
        $account = $this->xAccount($user);
        $fromNiche = $this->trends->rankedForWatch(
            $user,
            $account,
            $profile->resolvedNiche(),
            trim((string) $profile->description.' '.(string) $profile->audience),
        );
        $ranked = is_array($fromNiche) ? array_slice($fromNiche, 0, $prefilterLimit) : [];
        $ranked = $this->goals->orderMoments($this->goals->goals($profile), $ranked);
        $related = $ranked;

        if ($this->growthBrain->isEnabled()) {
            $ranked = $this->freshHotMoments($user, $ranked);
        }

        if ($this->postedMinutesAgo($user) === 9999) {
            $this->shareMoments = $related;
        }

        if ($ranked === []) {
            return $this->finish(
                $user,
                $profile,
                $this->logDecision($user, 'WAIT', null, 0, 'Nothing loud enough is happening to post', [])
            );
        }

        $this->momentsThisRun = $ranked;
        $this->sawHeat = $this->sawHeat || $this->listIsHot($ranked);

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

        if ($decision === 'POST' && $trend && $this->goals->prefersReplyFor($profile) && $this->goals->hasAudience($trend)) {
            $decision = 'ENGAGE';
            $reason = 'Reply under "'.$trend->name.'". It already has an audience, which is what this goal counts.';
        }

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
            $picked = $this->selectMoment($user, $profile, $ranked, $trend);
            if ($picked['row'] === null) {
                return $this->logDecision(
                    $user,
                    'WAIT',
                    'resting',
                    $score,
                    $picked['reason'],
                    $context
                );
            }

            /** @var \App\Models\Trend $chosen */
            $chosen = $picked['row']['trend'];
            $sameBeat = $trend && $this->beatKey($trend) === $this->beatKey($chosen);
            $angle = $sameBeat
                ? ($evaluation['content_angle'] ?: $this->bridgeAngle($chosen))
                : $this->bridgeAngle($chosen);

            return $this->actOnPostOpportunity(
                $user,
                $profile,
                $chosen,
                $angle,
                'trend',
                (float) $picked['row']['opportunity_score'],
                $reason,
                $context,
            );
        }

        if ($decision === 'ENGAGE' && ! $profile->auto_engage_enabled && ! ($profile->auto_reply_enabled && $this->goals->prefersReplyFor($profile))) {
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
        $picked = $this->selectMoment($user, $profile, $ranked);
        $top = $ranked[0];
        $trend = $picked['trend'] ?? $top['trend'];
        $score = (float) ($picked['score'] ?? $top['opportunity_score']);

        $context = [
            'engine' => 'rules_fallback',
            'trend_id' => $trend->id,
            'trend_name' => $trend->name,
            'competition' => $trend->competition,
            'growth_rate' => $trend->growth_rate,
            'mix' => $mix['prefer'],
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

        if ($picked['row'] === null) {
            return $this->logDecision($user, 'WAIT', 'resting', $score, $picked['reason'], $context);
        }

        if ($this->goals->prefersReplyFor($profile) && $this->goals->hasAudience($trend)) {
            return $this->logDecision(
                $user,
                'ENGAGE',
                'reply_under',
                $score,
                'Reply under "'.$trend->name.'". It already has an audience, which is what this goal counts.',
                $context
            );
        }

        $engageThreshold = $this->settings->getInt('rules_engage_threshold', 68);
        $postThreshold = $this->settings->getInt('rules_post_threshold', 82);

        if ($score >= $postThreshold || $this->isFreshSpike($trend, $score)) {
            return $this->actOnPostOpportunity(
                $user,
                $profile,
                $trend,
                $this->bridgeAngle($trend),
                'trend',
                $score,
                "People are on \"{$trend->name}\" ({$score}/100). This account's view goes on it",
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

    private function finish(User $user, AccountProfile $profile, string $decision): string
    {
        if (! $this->postedThisRun) {
            $this->maybeEngage($user, $profile);
            $this->maybeShare($user, $profile);
        }

        return $decision;
    }

    /**
     * @param  list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion?: string}>  $ranked
     * @return array{row: array{trend: \App\Models\Trend, opportunity_score: float}|null, reason: string, score: float, trend: \App\Models\Trend|null}
     */
    private function selectMoment(User $user, AccountProfile $profile, array $ranked, ?\App\Models\Trend $preferred = null): array
    {
        $ordered = $this->orderedMoments($ranked, $preferred);
        $unattended = $this->canUnattendedPost($profile);
        $threshold = $this->settings->getInt('rules_post_threshold', 82);
        $mild = null;
        $leftABigAccount = false;
        $offBrief = false;

        foreach ($ordered as $row) {
            $trend = $row['trend'];
            $score = (float) $row['opportunity_score'];
            if ($this->goals->skipsMoment($profile, $trend)) {
                $offBrief = true;

                continue;
            }
            if ($unattended && $this->beatAlreadyPosted($user, $trend)) {
                continue;
            }

            if ($unattended && $this->skippedThisBeat($user, $trend)) {
                $leftABigAccount = true;

                continue;
            }

            $hot = $score >= $threshold || $this->isFreshSpike($trend, $score);
            if ($unattended && $hot) {
                $gap = $this->gapHold($user, $trend, $score);
                if ($gap !== null) {
                    return ['row' => null, 'reason' => $gap, 'score' => $score, 'trend' => $trend];
                }

                return ['row' => $row, 'reason' => '', 'score' => $score, 'trend' => $trend];
            }

            if (! $unattended) {
                return ['row' => $row, 'reason' => '', 'score' => $score, 'trend' => $trend];
            }

            $mild ??= $row;
        }

        if (is_array($mild)) {
            return [
                'row' => $mild,
                'reason' => '',
                'score' => (float) $mild['opportunity_score'],
                'trend' => $mild['trend'],
            ];
        }

        $top = $ranked[0] ?? null;

        return [
            'row' => null,
            'reason' => $offBrief
                ? 'Left it. The moment is not about what this business sells.'
                : ($leftABigAccount
                    ? 'Left that one. Not every big account gets an answer.'
                    : 'Nothing new is loud enough to post.'),
            'score' => (float) ($top['opportunity_score'] ?? 0),
            'trend' => $top['trend'] ?? null,
        ];
    }

    /**
     * @param  list<array{trend: \App\Models\Trend, opportunity_score: float}>  $ranked
     * @return list<array{trend: \App\Models\Trend, opportunity_score: float}>
     */
    private function orderedMoments(array $ranked, ?\App\Models\Trend $preferred): array
    {
        if (! $preferred) {
            return $ranked;
        }

        $first = null;
        $rest = [];
        foreach ($ranked as $row) {
            if ($first === null && $this->beatKey($row['trend']) === $this->beatKey($preferred)) {
                $first = $row;

                continue;
            }
            $rest[] = $row;
        }

        if ($first === null) {
            array_unshift($ranked, [
                'trend' => $preferred,
                'opportunity_score' => (float) $preferred->score,
            ]);

            return $ranked;
        }

        array_unshift($rest, $first);

        return $rest;
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
        $context['content_source'] = $contentSource;
        $context['trend_name'] = $trend?->name;
        if ($trend) {
            $context['beat'] = $this->beatKey($trend);
        }

        $hold = $this->nichePostHoldReason($user, $profile, $trend, $score, $contentSource);
        if ($hold !== null) {
            return $this->logDecision($user, 'WAIT', 'resting', $score, $hold, $context);
        }

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

        return $this->publishOrHold($user, $profile, $draft, $trend, $score, $reason, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function publishOrHold(
        User $user,
        AccountProfile $profile,
        ?Post $draft,
        ?\App\Models\Trend $trend,
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

        $account = $this->xAccount($user);

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

        $hold = $this->nichePostHoldReason(
            $user,
            $profile,
            $trend,
            $score,
            (string) ($context['content_source'] ?? 'trend'),
        );
        if ($hold !== null) {
            return $this->logDecision($user, 'WAIT', 'resting', $score, $hold, $context);
        }

        if (! Cache::add($this->publishLockKey($user->id), 1, now()->addMinutes(2))) {
            return $this->logDecision(
                $user,
                'WAIT',
                'resting',
                $score,
                'A post is already going out, so this one waits.',
                $context
            );
        }

        try {
            $platformPostId = $this->xApi->publishText($account, $draft->content, $draft->content_type);
        } catch (RuntimeException $e) {
            Cache::forget($this->publishLockKey($user->id));
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
        Cache::forget($this->publishLockKey($user->id));
        $this->postedThisRun = true;
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

    /**
     * A new loud beat can post within minutes. The same beat cannot, and a quiet day does not post on a clock.
     */
    private function nichePostHoldReason(
        User $user,
        AccountProfile $profile,
        ?\App\Models\Trend $trend,
        float $score,
        string $contentSource,
    ): ?string {
        if (! $this->canUnattendedPost($profile)) {
            return null;
        }

        if ($contentSource === 'original' || trim((string) $trend?->name) === '') {
            return 'Nothing loud is happening, so nothing goes live.';
        }

        if ($trend === null) {
            return null;
        }

        if ($this->beatAlreadyPosted($user, $trend)) {
            return 'This moment was already posted. The next one waits for a different beat.';
        }

        $hot = $this->settings->getInt('rules_post_threshold', 82);
        if ($score < $hot && ! $this->isFreshSpike($trend, $score)) {
            return "The moment is {$score}/100. It needs {$hot} before it goes live.";
        }

        return $this->gapHold($user, $trend, $score);
    }

    private function gapHold(User $user, \App\Models\Trend $trend, float $score): ?string
    {
        $ago = $this->postedMinutesAgo($user);
        $gap = $this->gapMinutes($trend, $score);
        if ($ago < $gap) {
            return "A post went out {$ago} minutes ago. This next one waits for the moment, not a clock.";
        }

        return null;
    }

    private function gapMinutes(\App\Models\Trend $trend, float $score): int
    {
        $wobble = hexdec(substr(md5($this->beatKey($trend)), 0, 2)) % 10;
        if ($this->isFreshSpike($trend, $score)) {
            return 5 + ($wobble % 6);
        }

        if ($score >= 90) {
            return 8 + $wobble;
        }

        if ($score >= 85) {
            return 12 + $wobble;
        }

        return 25 + $wobble;
    }

    /**
     * The model is asked only about a story that is still hot and has not been judged yet.
     * A quiet timeline look does not call the model. The same story is not judged again for 7 days.
     *
     * @param  list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion?: string}>  $ranked
     * @return list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion?: string}>
     */
    private function freshHotMoments(User $user, array $ranked): array
    {
        $seen = Cache::get($this->seenMomentsKey($user->id));
        $seen = is_array($seen) ? $seen : [];
        $hot = [];

        foreach ($ranked as $row) {
            $key = $this->beatKey($row['trend']);
            if (isset($seen[$key])) {
                continue;
            }

            $score = (float) $row['opportunity_score'];
            if ($score < 82 && ! $this->isFreshSpike($row['trend'], $score)) {
                continue;
            }

            $seen[$key] = 1;
            $hot[] = $row;
        }

        if ($hot !== []) {
            Cache::put($this->seenMomentsKey($user->id), $seen, now()->addDays(7));
        }

        return $hot;
    }

    private function seenMomentsKey(int $userId): string
    {
        return 'growth-seen:v1:'.$userId;
    }

    private function isFreshSpike(\App\Models\Trend $trend, float $score): bool
    {
        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        $minutes = isset($meta['minutes_ago']) && is_numeric($meta['minutes_ago']) ? (int) $meta['minutes_ago'] : null;
        $followers = (int) ($meta['author_followers'] ?? 0);

        if ($minutes !== null && $minutes <= 25 && $followers >= 100000) {
            return true;
        }

        return (bool) ($meta['live'] ?? false) && $minutes !== null && $minutes <= 20 && $score >= 85;
    }

    private function beatAlreadyPosted(User $user, ?\App\Models\Trend $trend): bool
    {
        if (! $trend) {
            return false;
        }

        $beat = $this->beatKey($trend);
        $name = mb_strtolower(trim($trend->name));
        $posted = AiDecision::query()
            ->where('user_id', $user->id)
            ->where('action_taken', 'posted')
            ->where('created_at', '>=', now()->subDays(7))
            ->get(['context']);

        foreach ($posted as $decision) {
            $context = is_array($decision->context) ? $decision->context : [];
            $previousBeat = (string) ($context['beat'] ?? '');
            if ($previousBeat !== '' && $previousBeat === $beat) {
                return true;
            }
            $previousName = mb_strtolower(trim((string) ($context['trend_name'] ?? '')));
            if ($previousName !== '' && $previousName === $name) {
                return true;
            }
        }

        return false;
    }

    private function beatKey(\App\Models\Trend $trend): string
    {
        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        $id = trim((string) ($meta['tweet_id'] ?? ''));
        if ($id !== '') {
            return 'tweet:'.$id;
        }

        return 'name:'.mb_strtolower(trim($trend->name));
    }

    /**
     * @param  list<array{trend: \App\Models\Trend, opportunity_score: float}>  $ranked
     */
    private function listIsHot(array $ranked): bool
    {
        $top = $ranked[0] ?? null;
        if (! is_array($top)) {
            return false;
        }

        $trend = $top['trend'];
        $score = (float) $top['opportunity_score'];
        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        if (! empty($meta['live']) || $this->isFreshSpike($trend, $score)) {
            return true;
        }

        return $score >= 90;
    }

    private function skippedThisBeat(User $user, \App\Models\Trend $trend): bool
    {
        $meta = is_array($trend->metadata) ? $trend->metadata : [];
        if ((int) ($meta['author_followers'] ?? 0) < 100000) {
            return false;
        }

        $slot = hexdec(substr(md5($user->id.'|'.$this->beatKey($trend)), 0, 4)) % 2;

        return $slot === 1;
    }

    private function maybeEngage(User $user, AccountProfile $profile): void
    {
        if ($profile->engine_paused || (int) ($profile->automation_level ?? 1) < 2) {
            return;
        }

        $account = $this->xAccount($user);
        if (! $account) {
            return;
        }

        $sincePost = $this->postedMinutesAgo($user);
        if (! $this->goals->prefersReplyFor($profile) && ($sincePost === 9999 || $sincePost > 50)) {
            return;
        }

        $candidates = $this->goals->orderCandidates($this->goals->goals($profile), $this->engagementCandidates());
        if ($candidates === []) {
            return;
        }

        $liked = null;
        if ($profile->auto_engage_enabled && $this->canEngageNow($user, 'like', $this->budget->dailyLikes($user->id), 40)) {
            foreach ($candidates as $candidate) {
                if (! $this->wantsAction($user, $candidate['id'], 'like', 4)) {
                    continue;
                }
                if ($this->alreadyTouched($user, 'like', $candidate['id'])) {
                    continue;
                }
                try {
                    $this->xApi->likeTweet($account, $candidate['id']);
                    $this->safetyEngine->recordAction($user, 'like');
                    $this->rememberTouch($user, 'like', $candidate['id']);
                    Cache::put($this->engageGapKey($user->id, 'like'), now()->timestamp, now()->addDay());
                    $this->logDecision($user, 'ENGAGE', 'liked', null, 'Liked one post. Most posts are left alone.', [
                        'tweet_id' => $candidate['id'],
                    ]);
                    $liked = $candidate['id'];
                } catch (Throwable $e) {
                    Log::info('Like was skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    $this->rememberTouch($user, 'like', $candidate['id']);
                }
                break;
            }
        }

        if (! $profile->auto_reply_enabled || ! $this->budget->canReply($user->id, (int) $account->id) || ! $this->canEngageNow($user, 'reply', $this->budget->dailyReplies($user->id), 80)) {
            return;
        }

        $phrases = $this->goals->replyPhrases($profile);
        $replyLed = $this->goals->prefersReplyFor($profile);
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $liked || $this->alreadyTouched($user, 'reply', $candidate['id']) || $this->alreadyTouched($user, 'skip', $candidate['id'])) {
                continue;
            }
            if ($phrases !== [] && ! $this->xApi->sharesAny($candidate['text'], $phrases)) {
                $this->rememberTouch($user, 'skip', $candidate['id']);
                $this->logDecision($user, 'IGNORE', 'skipped_reply', null, 'Left this post. It does not match what this account is for.', [
                    'tweet_id' => $candidate['id'],
                    'author' => $candidate['author'] ?? 'Someone',
                    'theirs' => $candidate['text'],
                ]);

                continue;
            }
            if (! $replyLed && ! $this->wantsAction($user, $candidate['id'], 'reply', 5)) {
                continue;
            }
            $replyLed = false;
            try {
                $drafts = $this->aiCore->generateReplyDrafts([
                    'user' => $user,
                    'context' => $this->goals->replyBrief($profile),
                    'tone' => $profile->primaryTone(),
                    'niche' => $profile->resolvedNiche(),
                    'post_content' => $candidate['text'],
                    'voice' => trim($profile->description.' '.$profile->audience.' '.$profile->goalSentenceFor('x')),
                    'human' => true,
                    'allow_link' => $this->goals->allowsLink($profile),
                    'use_web_search' => false,
                ]);
                $text = $this->goals->finishText($profile, trim((string) ($drafts[0]['content'] ?? '')));
                if ($text === '') {
                    return;
                }
                $text = mb_substr($text, 0, 270);
                $platformId = $this->xApi->replyToTweet($account, $candidate['id'], $text);
                $user->posts()->create([
                    'content' => $text,
                    'content_type' => 'reply',
                    'status' => 'posted',
                    'platform_post_id' => $platformId,
                    'connected_account_id' => $account->id,
                    'posted_at' => now(),
                ]);
                $this->safetyEngine->recordAction($user, 'reply');
                $this->rememberTouch($user, 'reply', $candidate['id']);
                Cache::put($this->engageGapKey($user->id, 'reply'), now()->timestamp, now()->addDay());
                $this->logDecision($user, 'ENGAGE', 'replied', null, $this->goals->allowsLink($profile)
                    ? 'Replied and pointed at the destination.'
                    : 'Replied in this voice.', [
                    'tweet_id' => $candidate['id'],
                    'author' => $candidate['author'] ?? 'Someone',
                    'theirs' => $candidate['text'],
                    'ours' => $text,
                ]);
            } catch (Throwable $e) {
                Log::info('Reply was skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                $this->rememberTouch($user, 'reply', $candidate['id']);
            }

            return;
        }
    }

    /**
     * A related post from someone else can be reposted, and a second one can be quoted.
     * An account with nothing published can do that on this look. After one of its own posts, it waits 50 minutes.
     * Both stop when the day or the month is used.
     */
    private function maybeShare(User $user, AccountProfile $profile): void
    {
        if (! $this->canUnattendedPost($profile)) {
            return;
        }

        $sincePost = $this->postedMinutesAgo($user);
        if ($sincePost !== 9999 && $sincePost <= 50) {
            return;
        }

        $account = $this->xAccount($user);
        if (! $account) {
            return;
        }

        $floor = $this->settings->getInt('rules_engage_threshold', 68);
        $candidates = array_values(array_filter(
            $this->engagementCandidates($this->shareMoments !== [] ? $this->shareMoments : null),
            fn (array $candidate) => $candidate['score'] >= $floor
                && ! $this->alreadyTouched($user, 'repost', $candidate['id'])
                && ! $this->alreadyTouched($user, 'quote', $candidate['id'])
        ));
        if ($candidates === []) {
            return;
        }

        $reposted = null;
        $quoteTarget = $candidates[0];
        if (count($candidates) > 1 && $this->budget->canRepost($user->id) && $this->budget->canSpend($user->id, ApiSpendService::REPOST_MILLS)) {
            $reposted = $this->sendRepost($user, $account, $candidates[0]);
            $quoteTarget = $candidates[1];
        }

        if ($reposted !== null && $quoteTarget['id'] === $reposted) {
            return;
        }

        $quoteCost = ApiSpendService::QUOTE_MILLS + ApiSpendService::TWEET_RESERVE_MILLS;
        if (! $this->aiCore->isConfigured() || ! $this->budget->canQuote($user->id) || ! $this->budget->canSpend($user->id, $quoteCost)) {
            if ($reposted === null && $this->budget->canRepost($user->id) && $this->budget->canSpend($user->id, ApiSpendService::REPOST_MILLS)) {
                $this->sendRepost($user, $account, $candidates[0]);
            }

            return;
        }

        $this->sendQuote($user, $profile, $account, $quoteTarget);
    }

    /**
     * @param  array{id: string, text: string}  $candidate
     */
    private function sendRepost(User $user, \App\Models\ConnectedAccount $account, array $candidate): ?string
    {
        try {
            $this->xApi->repostTweet($account, $candidate['id']);
            $this->rememberTouch($user, 'repost', $candidate['id']);
            $this->logDecision($user, 'ENGAGE', 'reposted', null, 'Reposted one related post.', [
                'tweet_id' => $candidate['id'],
            ]);

            return $candidate['id'];
        } catch (Throwable $e) {
            Log::info('Repost was skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            $this->rememberTouch($user, 'repost', $candidate['id']);

            return null;
        }
    }

    /**
     * @param  array{id: string, text: string}  $candidate
     */
    private function sendQuote(User $user, AccountProfile $profile, \App\Models\ConnectedAccount $account, array $candidate): void
    {
        if ($this->alreadyTouched($user, 'quote', $candidate['id'])) {
            return;
        }

        try {
            $drafts = $this->aiCore->generateReplyDrafts([
                'user' => $user,
                'context' => $this->goals->replyBrief($profile),
                'tone' => $profile->primaryTone(),
                'niche' => $profile->resolvedNiche(),
                'post_content' => $candidate['text'],
                'human' => true,
                'allow_link' => $this->goals->allowsLink($profile),
                'use_web_search' => false,
            ]);
            $text = $this->goals->finishText($profile, trim((string) ($drafts[0]['content'] ?? '')));
            if ($text === '') {
                $this->rememberTouch($user, 'quote', $candidate['id']);

                return;
            }
            $platformId = $this->xApi->quoteTweet($account, $candidate['id'], $text);
            $user->posts()->create([
                'content' => $text,
                'content_type' => 'quote',
                'status' => 'posted',
                'platform_post_id' => $platformId,
                'connected_account_id' => $account->id,
                'posted_at' => now(),
            ]);
            $this->rememberTouch($user, 'quote', $candidate['id']);
            $this->logDecision($user, 'ENGAGE', 'quoted', null, 'Quoted one related post with a caption.', [
                'tweet_id' => $candidate['id'],
            ]);
        } catch (Throwable $e) {
            Log::info('Quote was skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            $this->rememberTouch($user, 'quote', $candidate['id']);
        }
    }

    /**
     * @param  list<array{trend: \App\Models\Trend, opportunity_score: float, suggestion?: string}>|null  $source
     * @return list<array{id: string, text: string, score: float, reach: int, replies: int, author: string}>
     */
    private function engagementCandidates(?array $source = null): array
    {
        $rows = [];
        foreach ($source ?? $this->momentsThisRun as $row) {
            $trend = $row['trend'];
            $meta = is_array($trend->metadata) ? $trend->metadata : [];
            $id = trim((string) ($meta['tweet_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $minutes = isset($meta['minutes_ago']) && is_numeric($meta['minutes_ago']) ? (int) $meta['minutes_ago'] : null;
            if ($minutes !== null && ($minutes < 2 || $minutes > 180)) {
                continue;
            }
            $text = trim((string) ($meta['text'] ?? $trend->name));
            if ($text === '') {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'text' => mb_substr($text, 0, 240),
                'author' => trim((string) ($meta['author'] ?? '')) ?: 'Someone',
                'score' => (float) ($row['opportunity_score'] ?? 0),
                'reach' => (int) ($meta['author_followers'] ?? 0),
                'replies' => (int) ($meta['replies'] ?? 0),
            ];
        }

        return $rows;
    }

    private function canEngageNow(User $user, string $action, int $dailyCap, int $gapMinutes): bool
    {
        if (! $this->safetyEngine->canAct($user, $action)) {
            return false;
        }

        $status = $this->safetyEngine->status($user);
        if ((int) ($status['actions'][$action]['count'] ?? 0) >= $dailyCap) {
            return false;
        }

        $last = Cache::get($this->engageGapKey($user->id, $action));
        if (! is_numeric($last)) {
            return true;
        }

        return (int) abs(now()->diffInMinutes(Carbon::createFromTimestamp((int) $last))) >= $gapMinutes;
    }

    private function wantsAction(User $user, string $tweetId, string $action, int $oneIn): bool
    {
        $oneIn = max(2, $oneIn);

        return hexdec(substr(md5($user->id.'|'.$tweetId.'|'.$action), 0, 4)) % $oneIn === 0;
    }

    private function alreadyTouched(User $user, string $action, string $tweetId): bool
    {
        return Cache::has($this->touchKey($user->id, $action, $tweetId));
    }

    private function rememberTouch(User $user, string $action, string $tweetId): void
    {
        Cache::put($this->touchKey($user->id, $action, $tweetId), 1, now()->addDays(7));
    }

    private function touchKey(int $userId, string $action, string $tweetId): string
    {
        return "engage:{$action}:{$userId}:".$this->scopeKey().":{$tweetId}";
    }

    private function engageGapKey(int $userId, string $action): string
    {
        return "engage:last:{$action}:{$userId}:".$this->scopeKey();
    }

    private function xAccount(User $user): ?\App\Models\ConnectedAccount
    {
        if ($this->accountThisRun && (int) $this->accountThisRun->user_id === (int) $user->id) {
            return $this->accountThisRun;
        }

        return $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    private function withVoice(AccountProfile $profile, \App\Models\ConnectedAccount $account): AccountProfile
    {
        if (! $account->hasOwnVoice()) {
            return $profile;
        }

        $copy = $profile->replicate();
        $niche = $account->nicheFor($profile);
        if ($niche !== '') {
            $copy->niche = $niche;
            $copy->custom_niche = $niche;
        }
        $copy->description = $account->descriptionFor($profile);
        $copy->audience = $account->audienceFor($profile);

        return $copy;
    }

    private function scopeKey(): string
    {
        return (string) ($this->accountThisRun?->id ?? '0');
    }

    private function postedMinutesAgo(User $user): int
    {
        $query = $user->posts()->where('status', 'posted')->where('content_type', 'tweet');
        if ($this->xAccountCount > 1 && $this->accountThisRun) {
            $query->where('connected_account_id', $this->accountThisRun->id);
        }
        $last = $query->latest('posted_at')->value('posted_at');
        if (! $last) {
            return 9999;
        }

        return (int) abs(Carbon::parse($last)->diffInMinutes(now()));
    }

    private function publishLockKey(int $userId): string
    {
        $account = $this->accountThisRun?->id ?? 'user';

        return "autopost:lock:{$userId}:{$account}";
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

        $accountId = $this->xAccount($user)?->id;
        $accountId = $accountId ? (int) $accountId : null;

        if (! $this->budget->canPost($user->id, 1, $accountId) || ! $this->budget->canSpend($user->id, ApiSpendService::POST_MILLS)) {
            return null;
        }

        $scope = $this->accountThisRun?->id ?? 'user';
        $draftsKey = 'growth-drafts:v1:'.$user->id.':'.$scope.':'.now()->toDateString();
        if ((int) Cache::get($draftsKey, 0) >= $this->budget->dailyPostTarget($user->id)) {
            return null;
        }

        $this->draftedThisRun = true;

        $isOriginal = $contentSource === 'original' || $trend === null;

        try {
            $metadata = is_array($trend?->metadata) ? $trend->metadata : [];
            $liveName = in_array($metadata['source'] ?? '', ['x', 'live'], true) ? $trend->name : null;
            $sourceText = trim((string) ($metadata['text'] ?? ''));
            $conversation = $sourceText !== '' ? $sourceText : $liveName;

            $params = [
                'user' => $user,
                'topic' => $conversation ?? 'a conversation people are having today',
                'conversation' => $conversation,
                'join_live' => true,
                'tone' => $profile->primaryTone(),
                'niche' => $profile->resolvedNiche(),
                'goal' => $profile->goalSentenceFor('x') ?: str_replace('_', ' ', $profile->primaryGoal()),
                'goal_instruction' => $this->goals->postBrief($profile),
                'goal_lesson' => $this->goals->results($profile)['lesson'],
                'content_type' => 'tweet',
                'audience' => $profile->audience,
                'description' => $profile->description,
                'stay_in_niche' => true,
                'loyalty' => is_string($metadata['loyalty'] ?? null) ? $metadata['loyalty'] : null,
                'planner_hint' => $this->safeHint($contentAngle, $profile),
                'variant_count' => 1,
                'recent_posts' => $this->voiceExamples($user),
                'use_web_search' => false,
                'score_virality' => true,
                'content_source' => $isOriginal ? 'original' : 'trend',
            ];
            if ($sourceText !== '') {
                $params['reaction'] = [
                    'text' => $sourceText,
                    'author' => $metadata['author'] ?? null,
                    'followers' => (int) ($metadata['author_followers'] ?? 0),
                    'minutes_ago' => $metadata['minutes_ago'] ?? null,
                ];
            }

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

            $content = $this->goals->finishText($profile, (string) $variant['content']);
            if ($content === '') {
                return null;
            }

            $draft = Post::create([
                'user_id' => $user->id,
                'connected_account_id' => $accountId,
                'trend_id' => $isOriginal ? null : $trend?->id,
                'content' => $content,
                'content_type' => 'tweet',
                'status' => 'draft',
                'virality_predicted' => $variant['virality_score'],
            ]);
            Cache::put($draftsKey, (int) Cache::get($draftsKey, 0) + 1, now()->endOfDay()->addHour());

            return $draft;
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

    /**
     * @return list<string>
     */
    private function voiceExamples(User $user): array
    {
        $account = $this->xAccount($user);
        $spoken = $account ? $this->xApi->voiceSamples($account) : [];
        $savedQuery = $user->posts()->latest()->limit(5);
        if ($this->xAccountCount > 1 && $account) {
            $savedQuery->where('connected_account_id', $account->id);
        }
        $saved = $savedQuery->pluck('content')->map(fn ($content) => (string) $content)->all();

        $lines = [];
        foreach (array_merge($spoken, $saved) as $line) {
            $line = trim(str_replace("\n", ' ', (string) $line));
            if ($line === '' || in_array($line, $lines, true)) {
                continue;
            }
            $lines[] = mb_substr($line, 0, 180);
            if (count($lines) === 6) {
                break;
            }
        }

        return $lines;
    }

    private function bridgeAngle(\App\Models\Trend $trend): string
    {
        $metadata = is_array($trend->metadata) ? $trend->metadata : [];
        $author = trim((string) ($metadata['author'] ?? ''));
        if ($author !== '' && (int) ($metadata['author_followers'] ?? 0) >= 100000) {
            return 'Your own view on what '.$author.' just said. Do not repeat their line';
        }

        if (! empty($metadata['adjacent'])) {
            return 'Your view on "'.$trend->name.'" from this account\'s side of the wider news';
        }

        return 'Your view on "'.$trend->name.'", not a recap';
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
            'connected_account_id' => $this->accountThisRun?->id,
            'decision' => $decision,
            'action_taken' => $action,
            'opportunity_score' => $score,
            'reason' => $reason,
            'context' => $context,
        ]);

        return $decision;
    }
}
