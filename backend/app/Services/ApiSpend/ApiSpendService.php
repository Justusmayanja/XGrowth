<?php

namespace App\Services\ApiSpend;

use App\Models\BillingSubscriptionLine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * One ledger per person for X reads, X writes, and OpenAI.
 * Amounts are thousandths of a dollar. The hard stop is about 35% of the
 * plan price, so a month that uses the full cap still leaves about 65%.
 */
class ApiSpendService
{
    /** Starter hard caps. Other plans read their own numbers from billing config. */
    public const MONTHLY_CAP_MILLS = 9450;

    public const DAILY_CAP_MILLS = 315;

    /** @var array<int, string> */
    private array $tiers = [];

    public const POST_MILLS = 15;

    public const LINK_POST_MILLS = 200;

    public const LIKE_MILLS = 15;

    public const REPOST_MILLS = 15;

    public const QUOTE_MILLS = 15;

    public const TWEET_RESERVE_MILLS = 8;

    public const SEARCH_RESERVE_MILLS = 25;

    public const SCRIPT_RESERVE_MILLS = 80;

    public function canSpend(int $userId, int $mills): bool
    {
        return $this->refusal($userId, $mills) === null;
    }

    public function refusal(int $userId, int $mills): ?string
    {
        if ($mills <= 0) {
            return null;
        }

        if ($this->spent($userId) + $mills > $this->monthlyCapMills($userId)) {
            return 'The monthly API budget for this account is used. It returns next month.';
        }

        if ($this->spentToday($userId) + $mills > $this->dailyCapMills($userId)) {
            return 'Today\'s API budget for this account is used. X and OpenAI wait until tomorrow.';
        }

        return null;
    }

    public function assertCanSpend(int $userId, int $mills): void
    {
        $message = $this->refusal($userId, $mills);
        if ($message !== null) {
            throw new RuntimeException($message);
        }
    }

    public function spend(int $userId, int $mills): void
    {
        if ($mills <= 0) {
            return;
        }

        Cache::put(
            $this->spendKey($userId),
            $this->spent($userId) + $mills,
            now()->endOfMonth()->addDay(),
        );
        Cache::put(
            $this->dailySpendKey($userId),
            $this->spentToday($userId) + $mills,
            now()->endOfDay()->addHour(),
        );
    }

    public function chargeOpenAi(int $userId, int $inputTokens, int $outputTokens, int $webSearches = 0): void
    {
        $dollars = ($inputTokens * 0.75 + $outputTokens * 4.50) / 1_000_000 + ($webSearches * 0.016);
        $this->spend($userId, (int) ceil($dollars * 1000));
        if ($webSearches > 0) {
            $this->addTally($userId, 'searches', $webSearches);
        }
    }

    /**
     * A ceiling for this calendar day, not a quota. Starter and Agency land on
     * 6, 7, or 8. Pro lands on 11, 12, or 13. Nothing posts on a clock to fill it.
     */
    public function dailyPostTarget(int $userId): int
    {
        $api = $this->api($userId);
        $low = (int) ($api['post_low'] ?? 6);
        $high = (int) ($api['post_high'] ?? 8);
        $span = max(0, $high - $low);

        return $low + (((int) now()->dayOfYear + $userId) % ($span + 1));
    }

    public function dailyReplies(int $userId): int
    {
        return (int) ($this->api($userId)['replies'] ?? 2);
    }

    public function dailyLikes(int $userId): int
    {
        return (int) ($this->api($userId)['likes'] ?? 2);
    }

    public function accountLimit(int $userId): int
    {
        $tier = $this->tier($userId);

        return max(1, (int) (config("billing.platforms.x.tiers.$tier.accounts") ?? 1));
    }

    public function showsFullDesk(int $userId): bool
    {
        return in_array($this->tier($userId), ['pro', 'agency'], true);
    }

    public function timelineCacheHours(int $userId): int
    {
        return max(1, (int) ($this->api($userId)['timeline_hours'] ?? 24));
    }

    public function activityCacheHours(int $userId): int
    {
        return max(1, (int) ($this->api($userId)['activity_hours'] ?? 168));
    }

    public function mentionCacheHours(int $userId): int
    {
        return max(1, (int) ($this->api($userId)['mention_hours'] ?? 168));
    }

    public function containsLink(string $text): bool
    {
        return preg_match('/https?:\/\/|www\./i', $text) === 1;
    }

    public function withoutLinks(string $text): string
    {
        $text = preg_replace('/https?:\/\/\S+|www\.\S+/i', '', $text) ?? $text;

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public function canPost(int $userId, int $count = 1, ?int $accountId = null): bool
    {
        return $this->tally($userId, 'posts', $accountId) + $count <= $this->dailyPostTarget($userId);
    }

    public function recordPost(int $userId, int $count = 1, ?int $accountId = null): void
    {
        $this->addTally($userId, 'posts', $count, $accountId);
    }

    public function canReply(int $userId, ?int $accountId = null): bool
    {
        return $this->tally($userId, 'replies', $accountId) < $this->dailyReplies($userId);
    }

    public function recordReply(int $userId, ?int $accountId = null): void
    {
        $this->addTally($userId, 'replies', 1, $accountId);
    }

    public function canLike(int $userId, ?int $accountId = null): bool
    {
        return $this->tally($userId, 'likes', $accountId) < $this->dailyLikes($userId);
    }

    public function recordLike(int $userId, ?int $accountId = null): void
    {
        $this->addTally($userId, 'likes', 1, $accountId);
    }

    public function canSearch(int $userId): bool
    {
        return $this->tally($userId, 'searches') < 1;
    }

    public function canRepost(int $userId): bool
    {
        return $this->tally($userId, 'reposts') < 1;
    }

    public function recordRepost(int $userId): void
    {
        $this->addTally($userId, 'reposts', 1);
    }

    public function canQuote(int $userId): bool
    {
        return $this->tally($userId, 'quotes') < 1;
    }

    public function recordQuote(int $userId): void
    {
        $this->addTally($userId, 'quotes', 1);
    }

    public function spendKey(int $userId): string
    {
        return 'api-spend:v1:'.$userId.':'.now()->format('Y-m');
    }

    public function dailySpendKey(int $userId): string
    {
        return 'api-spend:v1:'.$userId.':'.now()->toDateString();
    }

    private function spent(int $userId): int
    {
        return (int) Cache::get($this->spendKey($userId), 0);
    }

    private function spentToday(int $userId): int
    {
        return (int) Cache::get($this->dailySpendKey($userId), 0);
    }

    private function tally(int $userId, string $action, ?int $accountId = null): int
    {
        return (int) Cache::get($this->tallyKey($userId, $action, $accountId), 0);
    }

    private function addTally(int $userId, string $action, int $count, ?int $accountId = null): void
    {
        Cache::put(
            $this->tallyKey($userId, $action, $accountId),
            $this->tally($userId, $action, $accountId) + $count,
            now()->endOfDay()->addHour(),
        );
    }

    private function tallyKey(int $userId, string $action, ?int $accountId = null): string
    {
        $scope = $accountId ?? 0;

        return 'api-tally:v2:'.$userId.':'.$scope.':'.$action.':'.now()->toDateString();
    }

    public function tier(int $userId): string
    {
        if (isset($this->tiers[$userId])) {
            return $this->tiers[$userId];
        }

        $line = BillingSubscriptionLine::query()
            ->where('platform', 'x')
            ->whereHas('subscription', function ($query) use ($userId) {
                $query->where('user_id', $userId)->whereIn('status', ['active', 'trialing', 'past_due']);
            })
            ->latest('id')
            ->first();

        if ($line && in_array($line->tier, ['starter', 'pro', 'agency'], true)) {
            return $this->tiers[$userId] = $line->tier;
        }

        $plan = User::query()->whereKey($userId)->value('subscription_plan');

        return $this->tiers[$userId] = match ($plan) {
            'pro' => 'pro',
            'agency' => 'agency',
            default => 'starter',
        };
    }

    public function monthlyCapMills(int $userId): int
    {
        return (int) ($this->api($userId)['monthly_cap_mills'] ?? self::MONTHLY_CAP_MILLS) * $this->accountLimit($userId);
    }

    public function dailyCapMills(int $userId): int
    {
        return (int) ($this->api($userId)['daily_cap_mills'] ?? self::DAILY_CAP_MILLS) * $this->accountLimit($userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function api(int $userId): array
    {
        $tier = $this->tier($userId);
        $api = config("billing.platforms.x.tiers.$tier.api");

        return is_array($api) ? $api : (array) config('billing.platforms.x.tiers.starter.api');
    }
}
