<?php

namespace App\Services\YouTube;

use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionLine;
use App\Models\User;
use App\Models\YouTubeVideo;
use Carbon\CarbonImmutable;

class YouTubeAllowanceService
{
    /**
     * Monthly video allowance from the YouTube billing line. Posted videos are
     * counted. A queued approval does not spend the month, and a failed insert
     * does not either. This is separate from the shared daily insert budget.
     *
     * @return array{limit: int, used: int, remaining: int, period_start: string, period_end: string, plan_found: bool}
     */
    public function status(User $user): array
    {
        $line = $this->line($user);
        if (! $line || ! $line->subscription) {
            $start = CarbonImmutable::now('UTC')->startOfMonth();

            return $this->payload(0, 0, $start, $start->addMonth(), false);
        }

        [$start, $end] = $this->period($line->subscription);
        $used = YouTubeVideo::query()
            ->where('user_id', $user->id)
            ->where('status', 'posted')
            ->whereNotNull('posted_video_id')
            ->whereNotNull('posted_privacy')
            ->where('posted_at', '>=', $start)
            ->where('posted_at', '<', $end)
            ->count();

        return $this->payload((int) $line->monthly_allowance, $used, $start, $end, true);
    }

    private function line(User $user): ?BillingSubscriptionLine
    {
        $subscription = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->with('lines')
            ->orderByDesc('id')
            ->get()
            ->first(function (BillingSubscription $subscription) {
                return $this->inForce($subscription)
                    && $subscription->lines->contains(fn (BillingSubscriptionLine $line) => $line->platform === 'youtube');
            });

        return $subscription?->lines->first(fn (BillingSubscriptionLine $line) => $line->platform === 'youtube');
    }

    private function inForce(BillingSubscription $subscription): bool
    {
        if ($subscription->status === 'trialing') {
            return $subscription->trial_ends_at === null || $subscription->trial_ends_at->greaterThan(now());
        }

        if (! in_array($subscription->status, ['active', 'past_due'], true)) {
            return false;
        }

        if ($subscription->renews === false && $subscription->current_period_ends_at?->lessThanOrEqualTo(now())) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(BillingSubscription $subscription): array
    {
        if ($subscription->status === 'trialing') {
            $start = CarbonImmutable::parse($subscription->activated_at ?? $subscription->created_at ?? now())->utc();
            $end = CarbonImmutable::parse($subscription->trial_ends_at ?? $start->addDays((int) ($subscription->trial_days ?: 7)))->utc();

            return [$start, $end];
        }

        $end = $subscription->current_period_ends_at
            ? CarbonImmutable::parse($subscription->current_period_ends_at)->utc()
            : null;
        $start = $subscription->current_period_starts_at
            ? CarbonImmutable::parse($subscription->current_period_starts_at)->utc()
            : null;

        if ($end && ! $start) {
            $start = $end->subMonth();
        }

        if ($start && $end) {
            if ($subscription->interval === 'yearly' || $end->greaterThan($start->addDays(35))) {
                return $this->monthSlice($start, $end, CarbonImmutable::now('UTC'));
            }

            return [$start, $end];
        }

        $month = CarbonImmutable::now('UTC')->startOfMonth();

        return [$month, $month->addMonth()];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthSlice(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $now): array
    {
        $cursor = $periodStart;
        while ($cursor->addMonth()->lessThanOrEqualTo($now) && $cursor->addMonth()->lessThan($periodEnd)) {
            $cursor = $cursor->addMonth();
        }

        $sliceEnd = $cursor->addMonth();
        if ($sliceEnd->greaterThan($periodEnd)) {
            $sliceEnd = $periodEnd;
        }

        return [$cursor, $sliceEnd];
    }

    /**
     * @return array{limit: int, used: int, remaining: int, period_start: string, period_end: string, plan_found: bool}
     */
    private function payload(int $limit, int $used, CarbonImmutable $start, CarbonImmutable $end, bool $planFound): array
    {
        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'plan_found' => $planFound,
        ];
    }
}
