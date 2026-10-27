<?php

namespace App\Services\ApiSpend;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * One ledger per account for X reads, X writes, and OpenAI.
 * A day stops at $0.46. A month stops at $14. Amounts are thousandths of a dollar.
 */
class ApiSpendService
{
    public const MONTHLY_CAP_MILLS = 14000;

    public const DAILY_CAP_MILLS = 460;

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

        if ($this->spent($userId) + $mills > self::MONTHLY_CAP_MILLS) {
            return 'The monthly API budget for this account is used. It returns next month.';
        }

        if ($this->spentToday($userId) + $mills > self::DAILY_CAP_MILLS) {
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

    public function dailyPostTarget(int $userId): int
    {
        return 4 + (((int) now()->dayOfYear + $userId) % 3);
    }

    public function canPost(int $userId, int $count = 1): bool
    {
        return $this->tally($userId, 'posts') + $count <= $this->dailyPostTarget($userId);
    }

    public function recordPost(int $userId, int $count = 1): void
    {
        $this->addTally($userId, 'posts', $count);
    }

    public function canReply(int $userId): bool
    {
        return $this->tally($userId, 'replies') < 3;
    }

    public function recordReply(int $userId): void
    {
        $this->addTally($userId, 'replies', 1);
    }

    public function canLike(int $userId): bool
    {
        return $this->tally($userId, 'likes') < 2;
    }

    public function recordLike(int $userId): void
    {
        $this->addTally($userId, 'likes', 1);
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

    private function tally(int $userId, string $action): int
    {
        return (int) Cache::get($this->tallyKey($userId, $action), 0);
    }

    private function addTally(int $userId, string $action, int $count): void
    {
        Cache::put(
            $this->tallyKey($userId, $action),
            $this->tally($userId, $action) + $count,
            now()->endOfDay()->addHour(),
        );
    }

    private function tallyKey(int $userId, string $action): string
    {
        return 'api-tally:v1:'.$userId.':'.$action.':'.now()->toDateString();
    }
}
