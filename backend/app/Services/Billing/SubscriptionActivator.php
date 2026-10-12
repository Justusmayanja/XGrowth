<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionActivator
{
    public function __construct(private readonly GatewayManager $gateways) {}

    /**
     * Starter commercial access uses the existing Creator daily AI quota.
     * Pro and Agency keep their own quotas. The highest line wins.
     */
    public function accessPlanSlug(BillingSubscription $subscription): string
    {
        $rank = ['starter' => 1, 'pro' => 2, 'agency' => 3];
        $best = 'starter';

        foreach ($subscription->lines as $line) {
            if (($rank[$line->tier] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $line->tier;
            }
        }

        return $best === 'starter' ? 'creator' : $best;
    }

    public function activate(
        BillingSubscription $subscription,
        ?string $gatewaySubscriptionId,
        ?string $eventId,
    ): BillingSubscription {
        if (in_array($subscription->status, ['active', 'past_due', 'duplicate_payment'], true)) {
            return $subscription;
        }

        // A checkout the customer replaced with a newer one was still paid. Keep the newer
        // plan if it is already running and flag this payment for a refund.
        if ($subscription->status === 'canceled' && $this->newerPlanIsRunning($subscription)) {
            $subscription->update([
                'status' => 'duplicate_payment',
                'gateway_subscription_id' => $gatewaySubscriptionId ?: $subscription->gateway_subscription_id,
                'gateway_event_id' => $eventId,
            ]);
            $this->gateways->cancelRemote($subscription->fresh());

            Log::warning('Payment received for a replaced checkout; refund it in the gateway', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);

            return $subscription->fresh(['lines']);
        }

        $previous = BillingSubscription::query()
            ->where('user_id', $subscription->user_id)
            ->whereIn('status', ['active', 'past_due', 'trialing'])
            ->where('id', '!=', $subscription->id)
            ->get();

        foreach ($previous as $old) {
            if ($old->renews) {
                $this->gateways->cancelRemote($old);
            }
        }

        return DB::transaction(function () use ($subscription, $gatewaySubscriptionId, $eventId, $previous) {
            $locked = BillingSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'active') {
                return $locked->load('lines');
            }

            $now = now();

            $locked->update([
                'status' => 'active',
                'gateway_subscription_id' => $gatewaySubscriptionId ?: $locked->gateway_subscription_id,
                'gateway_event_id' => $eventId,
                'activated_at' => $now,
                'current_period_starts_at' => $now,
                'current_period_ends_at' => $locked->interval === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth(),
                'renews' => true,
                'next_plan' => null,
                'canceled_at' => null,
                'failure_reason' => null,
            ]);

            foreach ($previous as $old) {
                $old->update(['status' => 'replaced', 'renews' => false]);
            }

            User::query()->whereKey($locked->user_id)->update([
                'subscription_plan' => $this->accessPlanSlug($locked->load('lines')),
            ]);

            return $locked->fresh(['lines']);
        });
    }

    private function newerPlanIsRunning(BillingSubscription $subscription): bool
    {
        return BillingSubscription::query()
            ->where('user_id', $subscription->user_id)
            ->where('id', '>', $subscription->id)
            ->whereIn('status', ['active', 'past_due'])
            ->exists();
    }
}
