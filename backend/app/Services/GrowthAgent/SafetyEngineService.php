<?php

namespace App\Services\GrowthAgent;

use App\Models\RateLimitBudget;
use App\Models\User;
use Illuminate\Support\Carbon;

class SafetyEngineService
{
    private const DEFAULT_LIMITS = [
        'post' => 25,
        'reply' => 50,
        'follow' => 10,
        'like' => 100,
    ];

    public function canAct(User $user, string $actionType): bool
    {
        $budget = RateLimitBudget::firstOrCreate(
            [
                'user_id' => $user->id,
                'connected_account_id' => null,
                'action_type' => $actionType,
                'budget_date' => Carbon::today(),
            ],
            [
                'count' => 0,
                'daily_limit' => self::DEFAULT_LIMITS[$actionType] ?? 20,
            ]
        );

        return $budget->count < $budget->daily_limit;
    }

    public function recordAction(User $user, string $actionType): void
    {
        $budget = RateLimitBudget::firstOrCreate(
            [
                'user_id' => $user->id,
                'connected_account_id' => null,
                'action_type' => $actionType,
                'budget_date' => Carbon::today(),
            ],
            [
                'count' => 0,
                'daily_limit' => self::DEFAULT_LIMITS[$actionType] ?? 20,
            ]
        );

        $budget->increment('count');
    }

    public function status(User $user): array
    {
        $today = Carbon::today();
        $budgets = RateLimitBudget::where('user_id', $user->id)
            ->whereDate('budget_date', $today)
            ->get()
            ->keyBy('action_type');

        $actions = [];
        foreach (self::DEFAULT_LIMITS as $type => $limit) {
            $budget = $budgets->get($type);
            $actions[$type] = [
                'count' => $budget?->count ?? 0,
                'daily_limit' => $budget?->daily_limit ?? $limit,
            ];
        }

        return [
            'safety_score' => $this->computeSafetyScore($actions),
            'actions' => $actions,
        ];
    }

    public function resetDailyBudgets(): int
    {
        return RateLimitBudget::whereDate('budget_date', '<', Carbon::today())->delete();
    }

    private function computeSafetyScore(array $actions): int
    {
        $usage = 0;
        $capacity = 0;

        foreach ($actions as $data) {
            $usage += $data['count'];
            $capacity += $data['daily_limit'];
        }

        if ($capacity === 0) {
            return 100;
        }

        $usedPct = ($usage / $capacity) * 100;

        return (int) max(55, min(100, 100 - ($usedPct * 0.45)));
    }
}
