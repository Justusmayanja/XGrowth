<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionLine;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Support\Facades\DB;

class TrialService
{
    private const TIERS = ['starter', 'pro', 'agency'];

    public function __construct(
        private readonly BillingCatalog $catalog,
        private readonly SubscriptionActivator $activator,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function trialAvailable(User $user): bool
    {
        return ! BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNotNull('trial_ends_at')->orWhereNotNull('activated_at'))
            ->exists();
    }

    /**
     * Starts the trial on the first platform pick, and keeps a running trial's lines
     * in step with later picks. The trial length is fixed when the trial starts.
     *
     * @param  list<string>  $platforms
     * @param  array<string, string>  $tiers
     */
    public function syncForPlatforms(User $user, array $platforms, array $tiers = []): ?BillingSubscription
    {
        $this->expireForUser($user);

        if ($platforms === []) {
            return null;
        }

        $trial = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'trialing')
            ->with('lines')
            ->latest('id')
            ->first();

        if (! $trial && ! $this->trialAvailable($user)) {
            return null;
        }

        $current = $trial ? $trial->lines->pluck('tier', 'platform')->all() : [];
        $lines = [];

        foreach ($platforms as $platform) {
            $tier = $current[$platform] ?? $tiers[$platform] ?? 'starter';
            $lines[] = ['platform' => $platform, 'tier' => in_array($tier, self::TIERS, true) ? $tier : 'starter'];
        }

        $quote = $this->catalog->quote($lines, 'monthly');

        return DB::transaction(function () use ($user, $trial, $quote) {
            $totals = [
                'subtotal_cents' => $quote['subtotal_cents'],
                'discount_cents' => $quote['discount_cents'],
                'total_cents' => $quote['total_cents'],
            ];

            if ($trial) {
                $trial->update($totals);
                $trial->lines()->delete();
            } else {
                $days = $this->settings->trialDays();
                $trial = BillingSubscription::query()->create([
                    'user_id' => $user->id,
                    'status' => 'trialing',
                    'interval' => 'monthly',
                    'currency' => 'USD',
                    ...$totals,
                    'trial_days' => $days,
                    'trial_ends_at' => now()->addDays($days),
                    'activated_at' => now(),
                ]);
            }

            foreach ($quote['lines'] as $line) {
                BillingSubscriptionLine::query()->create([
                    'billing_subscription_id' => $trial->id,
                    'platform' => $line['platform'],
                    'tier' => $line['tier'],
                    'list_cents' => $line['list_cents'],
                    'charged_cents' => $line['charged_cents'],
                    'discount_cents' => $line['discount_cents'],
                    'is_anchor' => $line['is_anchor'],
                    'discount_limited' => $line['discount_limited'],
                    'accounts_included' => $line['accounts_included'],
                    'monthly_allowance' => $line['monthly_allowance'],
                    'allowance_unit' => $line['allowance_unit'],
                ]);
            }

            $trial->load('lines');

            User::query()->whereKey($user->id)->update([
                'subscription_plan' => $this->activator->accessPlanSlug($trial),
            ]);

            return $trial;
        });
    }

    public function expireForUser(User $user): void
    {
        $this->expire(BillingSubscription::query()->where('user_id', $user->id));
    }

    public function expireDue(): int
    {
        return $this->expire(BillingSubscription::query());
    }

    private function expire($query): int
    {
        $due = (clone $query)
            ->where('status', 'trialing')
            ->where('trial_ends_at', '<=', now())
            ->get(['id', 'user_id']);

        if ($due->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($due) {
            BillingSubscription::query()
                ->whereIn('id', $due->pluck('id'))
                ->update(['status' => 'trial_ended']);

            $stillPaying = BillingSubscription::query()
                ->whereIn('user_id', $due->pluck('user_id'))
                ->whereIn('status', ['active', 'past_due'])
                ->pluck('user_id');

            User::query()
                ->whereIn('id', $due->pluck('user_id')->diff($stillPaying))
                ->update(['subscription_plan' => 'creator']);
        });

        return $due->count();
    }
}
