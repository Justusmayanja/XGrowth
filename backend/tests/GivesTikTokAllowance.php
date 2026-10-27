<?php

namespace Tests;

use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionLine;
use App\Models\User;
use Carbon\CarbonInterface;

trait GivesTikTokAllowance
{
    protected function giveTikTokAllowance(
        User $user,
        int $allowance = 30,
        string $status = 'active',
        string $interval = 'monthly',
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null,
    ): BillingSubscription {
        $start ??= now()->utc()->subDay();
        $end ??= now()->utc()->addMonth();

        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'status' => $status,
            'interval' => $interval,
            'currency' => 'USD',
            'subtotal_cents' => 4900,
            'discount_cents' => 0,
            'total_cents' => 4900,
            'activated_at' => $start,
            'trial_days' => $status === 'trialing' ? 7 : null,
            'trial_ends_at' => $status === 'trialing' ? $end : null,
            'current_period_starts_at' => $status === 'trialing' ? null : $start,
            'current_period_ends_at' => $status === 'trialing' ? null : $end,
            'renews' => true,
        ]);

        BillingSubscriptionLine::query()->create([
            'billing_subscription_id' => $subscription->id,
            'platform' => 'tiktok',
            'tier' => 'starter',
            'list_cents' => 4900,
            'charged_cents' => 4900,
            'discount_cents' => 0,
            'is_anchor' => true,
            'accounts_included' => 1,
            'monthly_allowance' => $allowance,
            'allowance_unit' => 'clips',
        ]);

        return $subscription->load('lines');
    }
}
