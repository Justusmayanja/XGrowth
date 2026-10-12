<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Decides how a new platform mix replaces what the customer already pays for.
 *
 * - new: nothing paid is running, so the full price is due now.
 * - upgrade: worth at least as much per month as the current plan. Starts now, and the
 *   first payment is reduced by the unused part of the current period.
 * - downgrade: worth less per month, or yearly to monthly. Nothing is charged now. The
 *   current plan stops renewing and runs to the end of its period, then the new mix starts.
 * - same: the mix and billing interval already match the current plan.
 */
class PlanChangeService
{
    public const MIN_CHARGE_CENTS = 100;

    public function __construct(private readonly GatewayManager $gateways) {}

    public function currentPaid(User $user): ?BillingSubscription
    {
        return BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('gateway')
            ->with('lines')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array{kind: string, current: ?BillingSubscription, credit_cents: int, charge_now_cents: int, effective_at: ?CarbonInterface}
     */
    public function preview(User $user, array $quote): array
    {
        $current = $this->currentPaid($user);
        $total = (int) $quote['total_cents'];

        if (! $current || ! $current->current_period_ends_at || $current->current_period_ends_at->isPast()) {
            return $this->result('new', null, 0, $total, null);
        }

        if ($this->sameSelection($current, $quote)) {
            return $this->result('same', $current, 0, 0, null);
        }

        $credit = $this->unusedCredit($current);
        $toShorterInterval = $current->interval === 'yearly' && $quote['interval'] === 'monthly';
        $toLongerInterval = $current->interval === 'monthly' && $quote['interval'] === 'yearly';
        $cheaper = $this->monthlyValue($total, $quote['interval']) < $this->monthlyValue($current->total_cents, $current->interval);

        if ($toShorterInterval || ($cheaper && ! $toLongerInterval) || $total < $credit) {
            return $this->result('downgrade', $current, 0, 0, $current->current_period_ends_at);
        }

        $charge = max(min($total, self::MIN_CHARGE_CENTS), $total - $credit);

        return $this->result('upgrade', $current, $total - $charge, $charge, null);
    }

    public function unusedCredit(BillingSubscription $subscription): int
    {
        $end = $subscription->current_period_ends_at;

        if (! $end || $end->isPast()) {
            return 0;
        }

        $start = $subscription->current_period_starts_at
            ?? ($subscription->interval === 'yearly' ? $end->copy()->subYear() : $end->copy()->subMonth());
        $length = max(1.0, (float) $start->diffInSeconds($end));
        $remaining = max(0.0, (float) now()->diffInSeconds($end));

        return (int) floor($subscription->total_cents * min(1.0, $remaining / $length));
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    public function schedule(BillingSubscription $current, array $quote): BillingSubscription
    {
        $this->stopRenewal($current);

        $current->update([
            'next_plan' => [
                'interval' => $quote['interval'],
                'lines' => $this->quoteSelection($quote),
            ],
        ]);

        return $current->fresh(['lines']);
    }

    public function cancelRenewal(BillingSubscription $current): BillingSubscription
    {
        $this->stopRenewal($current);
        $current->update(['next_plan' => null]);

        return $current->fresh(['lines']);
    }

    private function stopRenewal(BillingSubscription $current): void
    {
        if ($current->renews) {
            $this->gateways->cancelRemote($current, atPeriodEnd: true);
        }

        $current->update([
            'renews' => false,
            'canceled_at' => $current->canceled_at ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function sameSelection(BillingSubscription $current, array $quote): bool
    {
        return $current->interval === $quote['interval']
            && $current->selection() === $this->quoteSelection($quote);
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<int, array{platform: string, tier: string}>
     */
    private function quoteSelection(array $quote): array
    {
        $lines = array_map(
            fn (array $line) => ['platform' => $line['platform'], 'tier' => $line['tier']],
            $quote['lines'],
        );
        usort($lines, fn (array $a, array $b) => strcmp($a['platform'], $b['platform']));

        return $lines;
    }

    private function monthlyValue(int $totalCents, string $interval): float
    {
        return $interval === 'yearly' ? $totalCents / 12 : (float) $totalCents;
    }

    /**
     * @return array{kind: string, current: ?BillingSubscription, credit_cents: int, charge_now_cents: int, effective_at: ?CarbonInterface}
     */
    private function result(string $kind, ?BillingSubscription $current, int $credit, int $charge, ?CarbonInterface $effectiveAt): array
    {
        return [
            'kind' => $kind,
            'current' => $current,
            'credit_cents' => $credit,
            'charge_now_cents' => $charge,
            'effective_at' => $effectiveAt,
        ];
    }
}
