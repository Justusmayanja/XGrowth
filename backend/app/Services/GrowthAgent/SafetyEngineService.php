<?php

namespace App\Services\GrowthAgent;

use App\Models\Post;
use App\Models\RateLimitBudget;
use App\Models\User;
use App\Services\ApiSpend\ApiSpendService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SafetyEngineService
{
    public function __construct(
        private readonly ApiSpendService $budget,
    ) {}

    public function canAct(User $user, string $actionType, ?int $accountId = null): bool
    {
        $accountId = $this->accountId($user, $accountId);

        if ($this->isFrozen($user, $accountId)) {
            return false;
        }

        if ($actionType === 'follow') {
            return false;
        }

        $row = $this->budgetRow($user, $actionType, $accountId);

        return $row->count < $row->daily_limit;
    }

    public function recordAction(User $user, string $actionType, ?int $accountId = null): void
    {
        $this->budgetRow($user, $actionType, $this->accountId($user, $accountId))->increment('count');
    }

    /**
     * Why this account should not post right now, or null if it can.
     */
    public function postHoldReason(User $user, ?int $accountId, bool $spike): ?string
    {
        $accountId = $this->accountId($user, $accountId);
        $freeze = $this->freezeState($user, $accountId);
        if ($freeze !== null) {
            return $freeze['reason'];
        }

        if (! $this->canAct($user, 'post', $accountId)) {
            return 'Today\'s post cap for this plan is used. The next one waits until tomorrow.';
        }

        if (! $spike && $this->roomIsQuiet($user, $accountId)) {
            return 'The last posts got no replies. The next one waits for a louder moment.';
        }

        return null;
    }

    public function noteXFailure(User $user, ?int $accountId, int $status, string $message): void
    {
        $hours = $this->freezeHours($status, $message);
        if ($hours === null) {
            return;
        }

        $accountId = $this->accountId($user, $accountId);
        $until = now()->addHours($hours);
        $reason = $status === 429
            ? 'X asked this account to slow down. Posting waits until '.$until->timezone(config('app.timezone'))->format('g:ia').'.'
            : 'X flagged this account. Posting waits until '.$until->timezone(config('app.timezone'))->format('g:ia').'.';

        Cache::put($this->freezeKey($user->id, $accountId), [
            'reason' => $reason,
            'until' => $until->toIso8601String(),
            'status' => $status,
        ], $until);
    }

    public function isFrozen(User $user, ?int $accountId = null): bool
    {
        return $this->freezeState($user, $this->accountId($user, $accountId)) !== null;
    }

    /**
     * @return array{safety_score: int, actions: array<string, array{count: int, daily_limit: int}>, frozen: bool, freeze_until: string|null, freeze_reason: string|null, quiet_room: bool, quiet_reason: string|null}
     */
    public function status(User $user, ?int $accountId = null): array
    {
        $accountId = $this->accountId($user, $accountId);
        $freeze = $this->freezeState($user, $accountId);
        $quiet = $this->roomIsQuiet($user, $accountId);
        $actions = [];

        foreach (['post', 'reply', 'like'] as $type) {
            $row = $this->budgetRow($user, $type, $accountId);
            $actions[$type] = [
                'count' => $row->count,
                'daily_limit' => $row->daily_limit,
            ];
        }

        return [
            'safety_score' => $this->computeSafetyScore($actions, $freeze !== null, $quiet),
            'actions' => $actions,
            'frozen' => $freeze !== null,
            'freeze_until' => $freeze['until'] ?? null,
            'freeze_reason' => $freeze['reason'] ?? null,
            'quiet_room' => $quiet,
            'quiet_reason' => $quiet ? 'The last posts got no replies. Wait for a louder moment.' : null,
        ];
    }

    public function resetDailyBudgets(): int
    {
        return RateLimitBudget::whereDate('budget_date', '<', Carbon::today())->delete();
    }

    /**
     * @return array{reason: string, until: string, status?: int}|null
     */
    private function freezeState(User $user, ?int $accountId): ?array
    {
        $state = Cache::get($this->freezeKey($user->id, $accountId));

        return is_array($state) && isset($state['reason']) ? $state : null;
    }

    private function freezeHours(int $status, string $message): ?int
    {
        $lower = mb_strtolower($message);

        if ($status === 429 || str_contains($lower, 'too many requests') || str_contains($lower, 'rate limit')) {
            return 6;
        }

        foreach (['locked', 'temporarily locked', 'automated', 'spam', 'violate', 'suspended'] as $cue) {
            if (str_contains($lower, $cue)) {
                return 24;
            }
        }

        if ($status === 403) {
            return 12;
        }

        return null;
    }

    private function roomIsQuiet(User $user, ?int $accountId): bool
    {
        $query = Post::query()
            ->where('user_id', $user->id)
            ->where('status', 'posted')
            ->where('content_type', 'tweet')
            ->latest('posted_at')
            ->limit(3);

        if ($accountId) {
            $query->where('connected_account_id', $accountId);
        }

        $posts = $query->get();
        if ($posts->count() < 3) {
            return false;
        }

        $known = $posts->filter(function (Post $post) {
            return (int) $post->impressions > 0
                || ($post->posted_at && $post->posted_at->lt(now()->subHours(6)));
        });

        return $known->count() >= 3 && $known->every(fn (Post $post) => (int) $post->replies <= 0);
    }

    private function budgetRow(User $user, string $actionType, ?int $accountId): RateLimitBudget
    {
        $limit = $this->dailyLimit($user, $actionType);
        $row = RateLimitBudget::firstOrCreate(
            [
                'user_id' => $user->id,
                'connected_account_id' => $accountId,
                'action_type' => $actionType,
                'budget_date' => Carbon::today(),
            ],
            [
                'count' => 0,
                'daily_limit' => $limit,
            ]
        );

        if ((int) $row->daily_limit !== $limit) {
            $row->update(['daily_limit' => $limit]);
            $row->daily_limit = $limit;
        }

        return $row;
    }

    private function dailyLimit(User $user, string $actionType): int
    {
        return match ($actionType) {
            'post' => $this->budget->dailyPostTarget($user->id),
            'reply' => $this->budget->dailyReplies($user->id),
            'like' => $this->budget->dailyLikes($user->id),
            default => 0,
        };
    }

    /**
     * @param  array<string, array{count: int, daily_limit: int}>  $actions
     */
    private function computeSafetyScore(array $actions, bool $frozen, bool $quiet): int
    {
        if ($frozen) {
            return 25;
        }

        $usage = 0;
        $capacity = 0;
        foreach ($actions as $data) {
            $usage += $data['count'];
            $capacity += $data['daily_limit'];
        }

        $score = 100;
        if ($capacity > 0) {
            $score = (int) max(40, min(100, 100 - ((($usage / $capacity) * 100) * 0.7)));
        }

        return $quiet ? min($score, 48) : $score;
    }

    private function accountId(User $user, ?int $accountId): ?int
    {
        if ($accountId) {
            return $accountId;
        }

        $id = $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function freezeKey(int $userId, ?int $accountId): string
    {
        return 'safety:freeze:'.$userId.':'.($accountId ?? 'user');
    }
}
