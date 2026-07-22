<?php

namespace App\Services\GrowthAgent;

use App\Models\RateLimitBudget;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Support\Carbon;

class SafetyEngineService
{
    public function __construct(
        private readonly PlatformSettingsService $settings,
    ) {}

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
                'daily_limit' => $this->dailyLimit($actionType),
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
                'daily_limit' => $this->dailyLimit($actionType),
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
        foreach (['post', 'reply', 'follow', 'like'] as $type) {
            $budget = $budgets->get($type);
            $actions[$type] = [
                'count' => $budget?->count ?? 0,
                'daily_limit' => $budget?->daily_limit ?? $this->dailyLimit($type),
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

    private function dailyLimit(string $actionType): int
    {
        return match ($actionType) {
            'post' => $this->settings->getInt('safety_limit_post', 25),
            'reply' => $this->settings->getInt('safety_limit_reply', 50),
            'follow' => $this->settings->getInt('safety_limit_follow', 10),
            'like' => $this->settings->getInt('safety_limit_like', 100),
            default => 20,
        };
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
