<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingLifecycle
{
    public const RENEWAL_GRACE_DAYS = 3;

    public function renew(BillingSubscription $subscription, CarbonInterface $startsAt, CarbonInterface $endsAt, ?string $reference): void
    {
        if ($reference !== null && $reference === $subscription->last_renewal_ref) {
            return;
        }

        $subscription->update([
            'status' => 'active',
            'current_period_starts_at' => $startsAt,
            'current_period_ends_at' => $endsAt,
            'last_renewal_ref' => $reference ?? $subscription->last_renewal_ref,
            'failure_reason' => null,
        ]);
    }

    /**
     * Paddle subscription.* events carry the billing period Paddle is using, so they are the
     * source of truth for renewals, failed payments, and cancellations.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyPaddleSubscription(string $event, array $data): void
    {
        $remoteId = isset($data['id']) ? (string) $data['id'] : '';
        $subscription = $remoteId === '' ? null : BillingSubscription::query()
            ->where('gateway', 'paddle')
            ->where('gateway_subscription_id', $remoteId)
            ->whereIn('status', ['active', 'past_due'])
            ->first();

        if (! $subscription) {
            return;
        }

        $status = (string) ($data['status'] ?? '');
        $period = is_array($data['current_billing_period'] ?? null) ? $data['current_billing_period'] : null;
        $cancelScheduled = ($data['scheduled_change']['action'] ?? null) === 'cancel';

        if ($status === 'canceled' || $event === 'subscription.canceled') {
            $subscription->update(['renews' => false, 'canceled_at' => $subscription->canceled_at ?? now()]);
            $this->endIfOver($subscription->fresh());

            return;
        }

        if ($status === 'past_due') {
            $subscription->update(['status' => 'past_due', 'failure_reason' => 'The renewal payment failed. Paddle is retrying.']);

            return;
        }

        if ($status === 'active' && $period && isset($period['starts_at'], $period['ends_at'])) {
            $this->renew(
                $subscription,
                Carbon::parse($period['starts_at']),
                Carbon::parse($period['ends_at']),
                null,
            );

            if ($cancelScheduled && $subscription->renews) {
                $subscription->update(['renews' => false, 'canceled_at' => now()]);
            }
        }
    }

    /**
     * Flutterwave renewal charges carry a new tx_ref and no plan ID, so they are matched to
     * the customer's running Flutterwave plan by email and amount.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyFlutterwaveRenewal(array $data): bool
    {
        $email = strtolower(trim((string) ($data['customer']['email'] ?? '')));
        $paidCents = (int) round(((float) ($data['amount'] ?? 0)) * 100);
        $reference = isset($data['id']) ? (string) $data['id'] : null;

        if ($email === '' || ($data['status'] ?? null) !== 'successful' || strtoupper((string) ($data['currency'] ?? '')) !== 'USD') {
            return false;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $subscription = $user ? BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('gateway', 'flutterwave')
            ->whereIn('status', ['active', 'past_due'])
            ->where('renews', true)
            ->where('total_cents', '<=', $paidCents)
            ->latest('id')
            ->first() : null;

        if (! $subscription) {
            Log::warning('Flutterwave charge did not match a running plan', ['reference' => $reference]);

            return false;
        }

        $end = $subscription->current_period_ends_at;
        $startsAt = $end && $end->greaterThan(now()->subDays(self::RENEWAL_GRACE_DAYS)) ? $end : now();
        $endsAt = $subscription->interval === 'yearly' ? $startsAt->copy()->addYear() : $startsAt->copy()->addMonth();

        $this->renew($subscription, $startsAt, $endsAt, $reference);

        return true;
    }

    public function applyFlutterwaveCancellation(string $planId): void
    {
        $subscription = BillingSubscription::query()
            ->where('gateway', 'flutterwave')
            ->where('gateway_subscription_id', $planId)
            ->whereIn('status', ['active', 'past_due'])
            ->first();

        if (! $subscription) {
            return;
        }

        $subscription->update(['renews' => false, 'canceled_at' => $subscription->canceled_at ?? now()]);
        $this->endIfOver($subscription->fresh());
    }

    /**
     * Ends plans whose paid period ran out without renewing, and flags renewals the gateway
     * never confirmed.
     *
     * @return array{ended: int, past_due: int}
     */
    public function sweep(): array
    {
        $ended = 0;

        BillingSubscription::query()
            ->whereIn('status', ['active', 'past_due'])
            ->where('renews', false)
            ->where('current_period_ends_at', '<=', now())
            ->each(function (BillingSubscription $subscription) use (&$ended) {
                $this->end($subscription);
                $ended++;
            });

        $pastDue = BillingSubscription::query()
            ->where('status', 'active')
            ->where('renews', true)
            ->whereNotNull('gateway')
            ->where('current_period_ends_at', '<=', now()->subDays(self::RENEWAL_GRACE_DAYS))
            ->update([
                'status' => 'past_due',
                'failure_reason' => 'The renewal payment has not been confirmed.',
            ]);

        return ['ended' => $ended, 'past_due' => $pastDue];
    }

    private function endIfOver(BillingSubscription $subscription): void
    {
        if (! $subscription->current_period_ends_at || $subscription->current_period_ends_at->isPast()) {
            $this->end($subscription);
        }
    }

    private function end(BillingSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => 'ended', 'renews' => false]);

            $stillPaying = BillingSubscription::query()
                ->where('user_id', $subscription->user_id)
                ->whereIn('status', ['active', 'past_due', 'trialing'])
                ->exists();

            if (! $stillPaying) {
                User::query()->whereKey($subscription->user_id)->update(['subscription_plan' => 'creator']);
            }
        });
    }
}
