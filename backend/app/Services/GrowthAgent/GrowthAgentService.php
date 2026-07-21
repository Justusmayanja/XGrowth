<?php

namespace App\Services\GrowthAgent;

use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\User;
use App\Services\TrendEngine\OpportunityScorerService;

class GrowthAgentService
{
    private const POST_THRESHOLD = 82;

    private const ENGAGE_THRESHOLD = 68;

    public function __construct(
        private readonly SafetyEngineService $safetyEngine,
        private readonly OpportunityScorerService $opportunityScorer,
    ) {}

    public function evaluateAllUsers(): int
    {
        $count = 0;

        User::query()
            ->where('is_blocked', false)
            ->whereHas('accountProfile', fn ($q) => $q->where('onboarding_completed', true))
            ->each(function (User $user) use (&$count) {
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

        $ranked = $this->opportunityScorer->rankedForProfile($user->accountProfile, 1);

        if (empty($ranked)) {
            $this->logDecision($user, 'WAIT', null, 0, 'No trends available to evaluate yet', []);

            return 'WAIT';
        }

        $top = $ranked[0];
        /** @var \App\Models\Trend $trend */
        $trend = $top['trend'];
        $score = $top['opportunity_score'];
        $automation = $user->accountProfile->automation_level ?? 1;

        $context = [
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

        if ($score >= self::POST_THRESHOLD) {
            if ($automation >= 2) {
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
                "Strong opportunity on \"{$trend->name}\" — review suggested draft in Content Studio",
                $context
            );
        }

        if ($score >= self::ENGAGE_THRESHOLD) {
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
     * Decides POST / WAIT / ENGAGE / IGNORE for one account.
     */
    public function evaluate(User $user, AccountProfile $profile, float $opportunityScore): string
    {
        if (! $this->safetyEngine->canAct($user, 'post')) {
            $this->logDecision($user, 'WAIT', null, $opportunityScore, 'Safety limits reached', []);

            return 'WAIT';
        }

        if ($opportunityScore >= self::POST_THRESHOLD) {
            $this->logDecision($user, 'POST', 'evaluate_opportunity', $opportunityScore, 'High opportunity score', []);

            return 'POST';
        }

        if ($opportunityScore >= self::ENGAGE_THRESHOLD) {
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
