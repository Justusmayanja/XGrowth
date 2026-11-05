<?php

namespace App\Services\TikTok;

use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionLine;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\Billing\TrialService;
use Carbon\CarbonImmutable;

class TikTokAllowanceService
{
    public function __construct(private readonly TrialService $trials) {}

    /**
     * The clip allowance is counted, never stored.
     *
     * A trial's window is activated_at through trial_ends_at. A paid plan uses
     * current_period_starts_at and current_period_ends_at. monthly_allowance stays
     * monthly, so a yearly payment period is split into month-sized slices anchored
     * on the period start. If the start is missing, the slice is one month back from
     * the end. With no dates at all, the window is the UTC calendar month.
     *
     * @return array{limit: int, used: int, remaining: int, period_start: string, period_end: string, plan_found: bool}
     */
    public function status(User $user, ?TikTokClip $excluding = null): array
    {
        $line = $this->line($user);
        if (! $line || ! $line->subscription) {
            $start = CarbonImmutable::now('UTC')->startOfMonth();

            return $this->payload(0, 0, $start, $start->addMonth(), false);
        }

        [$start, $end] = $this->period($line->subscription);
        $used = $this->used($user, $start, $end, $excluding);

        return $this->payload((int) $line->monthly_allowance, $used, $start, $end, true);
    }

    public function canQueue(User $user, ?TikTokClip $excluding = null): bool
    {
        $status = $this->status($user, $excluding);
        if (! $status['plan_found']) {
            return false;
        }

        $now = CarbonImmutable::now('UTC');
        $start = CarbonImmutable::parse($status['period_start']);
        $end = CarbonImmutable::parse($status['period_end']);
        if ($now->lt($start) || $now->greaterThanOrEqualTo($end)) {
            return false;
        }

        return $status['used'] < $status['limit'];
    }

    private function line(User $user): ?BillingSubscriptionLine
    {
        $statuses = ['trialing', 'active', 'past_due'];
        if ($this->trials->paused()) {
            $statuses[] = 'trial_ended';
        }

        $subscription = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', $statuses)
            ->with('lines')
            ->orderByDesc('id')
            ->get()
            ->first(function (BillingSubscription $subscription) {
                return $this->inForce($subscription)
                    && $subscription->lines->contains(fn (BillingSubscriptionLine $line) => $line->platform === 'tiktok');
            });

        return $subscription?->lines->first(fn (BillingSubscriptionLine $line) => $line->platform === 'tiktok');
    }

    /**
     * Canceled plans stay in force until the paid period ends. A trial that has
     * reached trial_ends_at is over even if the expiry job has not flipped the status.
     * past_due still counts: the lifecycle treats that customer as still paying.
     */
    private function inForce(BillingSubscription $subscription): bool
    {
        if (in_array($subscription->status, ['trialing', 'trial_ended'], true)) {
            return $this->trials->inForce($subscription);
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
        if (in_array($subscription->status, ['trialing', 'trial_ended'], true)) {
            return $this->trials->window($subscription);
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

    private function used(User $user, CarbonImmutable $start, CarbonImmutable $end, ?TikTokClip $excluding): int
    {
        return TikTokClip::query()
            ->forUser($user)
            ->whereNull('discarded_at')
            ->whereNotNull('queued_at')
            ->where('queued_at', '>=', $start)
            ->where('queued_at', '<', $end)
            ->when($excluding, fn ($query) => $query->whereKeyNot($excluding->id))
            ->count();
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
