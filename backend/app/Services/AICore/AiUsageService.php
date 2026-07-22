<?php

namespace App\Services\AICore;

use App\Models\AiUsageLog;
use App\Models\RateLimitBudget;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Support\Carbon;
use RuntimeException;

class AiUsageService
{
    public const ACTION_GENERATION = 'ai_generation';

    public const ACTION_WEB_SEARCH = 'web_search';

    public function __construct(
        private readonly PlatformSettingsService $settings,
    ) {}

    public function assertCanGenerate(User $user): void
    {
        if (! $this->canUse($user, self::ACTION_GENERATION)) {
            throw new RuntimeException($this->limitMessage($user, self::ACTION_GENERATION));
        }
    }

    public function assertCanWebSearch(User $user): void
    {
        if (! $this->settings->getBool('web_search_enabled', true)) {
            throw new RuntimeException('Web search is disabled on this platform.');
        }

        if (! $this->canUse($user, self::ACTION_WEB_SEARCH)) {
            throw new RuntimeException($this->limitMessage($user, self::ACTION_WEB_SEARCH));
        }
    }

    public function canUse(User $user, string $actionType): bool
    {
        $budget = $this->budgetFor($user, $actionType);

        return $budget->count < $budget->daily_limit;
    }

    /**
     * @param  array{
     *     feature: string,
     *     model: string,
     *     input_tokens?: int,
     *     output_tokens?: int,
     *     total_tokens?: int,
     *     web_search_calls?: int,
     *     used_web_search?: bool,
     *     metadata?: array<string, mixed>|null
     * }  $usage
     */
    public function record(User $user, array $usage): void
    {
        $this->increment($user, self::ACTION_GENERATION);

        $webSearchCalls = (int) ($usage['web_search_calls'] ?? 0);
        if ($webSearchCalls > 0) {
            $this->increment($user, self::ACTION_WEB_SEARCH, $webSearchCalls);
        }

        AiUsageLog::create([
            'user_id' => $user->id,
            'feature' => $usage['feature'],
            'model' => $usage['model'],
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'web_search_calls' => $webSearchCalls,
            'used_web_search' => (bool) ($usage['used_web_search'] ?? false),
            'metadata' => $usage['metadata'] ?? null,
        ]);
    }

    public function status(User $user): array
    {
        $generation = $this->budgetSnapshot($user, self::ACTION_GENERATION);
        $webSearch = $this->budgetSnapshot($user, self::ACTION_WEB_SEARCH);
        $plan = $this->planFor($user);

        return [
            'plan' => $user->subscription_plan,
            'web_search_enabled' => $this->settings->getBool('web_search_enabled', true),
            'ai_generations' => $generation,
            'web_searches' => $webSearch,
            'plan_limits' => [
                'daily_ai_generations' => $plan?->daily_ai_generations ?? config('ai.defaults.daily_ai_generations', 30),
                'daily_web_searches' => $plan?->daily_web_searches ?? config('ai.defaults.daily_web_searches', 5),
            ],
            'cost_notes' => [
                'web_search_tool_fee_usd' => config('ai.web_search.cost_per_search_usd', 0.01),
                'estimated_search_input_tokens' => config('ai.web_search.estimated_search_input_tokens', 8000),
            ],
        ];
    }

    private function increment(User $user, string $actionType, int $amount = 1): void
    {
        $budget = $this->budgetFor($user, $actionType);
        $budget->increment('count', $amount);
    }

    private function budgetSnapshot(User $user, string $actionType): array
    {
        $budget = $this->budgetFor($user, $actionType);

        return [
            'used' => $budget->count,
            'limit' => $budget->daily_limit,
            'remaining' => max(0, $budget->daily_limit - $budget->count),
        ];
    }

    private function budgetFor(User $user, string $actionType): RateLimitBudget
    {
        return RateLimitBudget::firstOrCreate(
            [
                'user_id' => $user->id,
                'connected_account_id' => null,
                'action_type' => $actionType,
                'budget_date' => Carbon::today(),
            ],
            [
                'count' => 0,
                'daily_limit' => $this->dailyLimitFor($user, $actionType),
            ]
        );
    }

    private function dailyLimitFor(User $user, string $actionType): int
    {
        $plan = $this->planFor($user);

        return match ($actionType) {
            self::ACTION_GENERATION => $plan?->daily_ai_generations ?? config('ai.defaults.daily_ai_generations', 30),
            self::ACTION_WEB_SEARCH => $plan?->daily_web_searches ?? config('ai.defaults.daily_web_searches', 5),
            default => 0,
        };
    }

    private function planFor(User $user): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()->find($user->subscription_plan);
    }

    private function limitMessage(User $user, string $actionType): string
    {
        $snapshot = $this->budgetSnapshot($user, $actionType);

        return match ($actionType) {
            self::ACTION_WEB_SEARCH => "Daily web search limit reached ({$snapshot['limit']} per day on your {$user->subscription_plan} plan). Web search uses extra tokens and tool fees — try again tomorrow or generate without web search.",
            default => "Daily AI generation limit reached ({$snapshot['limit']} per day on your {$user->subscription_plan} plan). Try again tomorrow or upgrade your plan.",
        };
    }
}
