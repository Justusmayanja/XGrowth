<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionLine;
use App\Models\PlatformPrice;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\Billing\BillingCatalog;
use App\Services\Billing\BillingGatewayException;
use App\Services\Billing\GatewayManager;
use App\Services\Billing\PlanChangeService;
use App\Services\Billing\SubscriptionActivator;
use App\Services\Billing\TrialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingCatalog $catalog,
        private readonly GatewayManager $gateways,
        private readonly SubscriptionActivator $activator,
        private readonly TrialService $trials,
        private readonly PlanChangeService $plans,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function catalog(): JsonResponse
    {
        return response()->json([
            ...$this->catalog->publicCatalog(),
            'trial_days' => $this->settings->trialDays(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $validated = $this->validatedSelection($request);

        try {
            $quote = $this->catalog->quote($validated['lines'], $validated['interval']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($quote);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $this->validatedSelection($request);

        try {
            $quote = $this->catalog->quote($validated['lines'], $validated['interval']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            ...$quote,
            'change' => $this->presentChange($this->plans->preview($request->user(), $quote)),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $this->validatedSelection($request);
        $user = $request->user();

        try {
            $quote = $this->catalog->quote($validated['lines'], $validated['interval']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $change = $this->plans->preview($user, $quote);

        if ($change['kind'] === 'same') {
            $current = $change['current'];

            if ($current->renews) {
                return response()->json(['message' => 'This is already your plan.', 'code' => 'same_plan'], 422);
            }

            // The customer had stopped renewal or scheduled a change, and now wants to stay.
            $current = $this->plans->schedule($current, $quote);

            return response()->json([
                'scheduled' => true,
                'effective_at' => $current->current_period_ends_at?->toIso8601String(),
                'subscription' => $this->present($current),
            ]);
        }

        if ($change['kind'] === 'downgrade') {
            $current = $this->plans->schedule($change['current'], $quote);

            return response()->json([
                'scheduled' => true,
                'effective_at' => $current->current_period_ends_at?->toIso8601String(),
                'subscription' => $this->present($current),
            ]);
        }

        if (! $this->gateways->isConfigured()) {
            $missing = $this->gateways->missingKeys();
            Log::warning('Billing checkout blocked, gateway keys missing', ['missing' => $missing]);

            return response()->json([
                'message' => app()->environment('production')
                    ? 'Checkout is not open yet.'
                    : 'Billing gateway needs API keys in the backend environment: '.implode(', ', $missing).'.',
                'code' => 'gateway_not_configured',
                'missing' => app()->environment('production') ? [] : $missing,
            ], 503);
        }

        BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'canceled']);

        $subscription = DB::transaction(function () use ($user, $quote, $change) {
            $subscription = BillingSubscription::query()->create([
                'user_id' => $user->id,
                'status' => 'pending',
                'interval' => $quote['interval'],
                'currency' => 'USD',
                'subtotal_cents' => $quote['subtotal_cents'],
                'discount_cents' => $quote['discount_cents'],
                'total_cents' => $quote['total_cents'],
                'first_charge_cents' => $change['charge_now_cents'],
                'credit_cents' => $change['credit_cents'],
                'replaces_subscription_id' => $change['current']?->id,
                'gateway' => $this->gateways->driver(),
            ]);

            $this->createLines($subscription, $quote);

            return $subscription;
        });

        try {
            $session = $this->gateways->createCheckout($subscription, $quote, $user);
        } catch (BillingGatewayException $exception) {
            $subscription->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 502);
        }

        $subscription->update([
            'gateway_reference' => $session['reference'],
            'gateway_subscription_id' => $session['subscription_id'],
            'checkout_url' => $session['url'],
        ]);

        return response()->json([
            'checkout_url' => $session['url'],
            'subscription_id' => $subscription->id,
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $current = $this->plans->currentPaid($request->user());

        if (! $current) {
            return response()->json(['message' => 'There is no paid plan to cancel.'], 422);
        }

        $current = $this->plans->cancelRenewal($current);

        return response()->json(['subscription' => $this->present($current)]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->trials->expireForUser($user);
        $subscription = $this->findOwned($request);

        return response()->json([
            'subscription' => $subscription ? $this->present($subscription) : null,
            'trial_available' => $this->trials->trialAvailable($user),
            'trial_days' => $this->settings->trialDays(),
            'next_plan' => $this->nextPlan($user, $subscription),
        ]);
    }

    public function confirm(Request $request, BillingSubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        if ($subscription->status === 'active') {
            return response()->json([
                'paid' => true,
                'subscription' => $this->present($subscription->load('lines')),
            ]);
        }

        if ($subscription->status !== 'pending') {
            return response()->json([
                'message' => 'This checkout is no longer waiting for payment.',
                'paid' => false,
                'subscription' => $this->present($subscription->load('lines')),
            ], 409);
        }

        try {
            $payment = $this->gateways->fetchPayment($subscription);
        } catch (BillingGatewayException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'paid' => false], 502);
        }

        if (! $payment['paid']) {
            return response()->json([
                'paid' => false,
                'subscription' => $this->present($subscription->load('lines')),
            ]);
        }

        $activated = $this->activator->activate(
            $subscription,
            $payment['gateway_subscription_id'],
            'confirm',
        );

        return response()->json([
            'paid' => true,
            'subscription' => $this->present($activated),
        ]);
    }

    public function adminPrices(): JsonResponse
    {
        return response()->json([
            'gateway' => $this->gateways->driver() ?: (string) config('billing.gateway'),
            'configured' => $this->gateways->isConfigured(),
            'missing' => $this->gateways->missingKeys(),
            'paddle_environment' => (string) config('billing.paddle.environment'),
            'discount_percent' => $this->catalog->bundleDiscountPercent(),
            'prices' => $this->catalog->adminPrices(),
        ]);
    }

    public function updatePrices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discount_percent' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.platform' => ['required', 'string', 'in:x,tiktok,youtube'],
            'prices.*.tier' => ['required', 'string', 'in:starter,pro,agency'],
            'prices.*.list_price_cents' => ['required', 'integer', 'min:100', 'max:10000000'],
            'prices.*.yearly_price_cents' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:120000000'],
        ]);

        $current = collect($this->catalog->adminPrices())->keyBy(fn (array $row) => $row['platform'].':'.$row['tier']);
        $seen = [];

        foreach ($validated['prices'] as $price) {
            $key = $price['platform'].':'.$price['tier'];

            if (isset($seen[$key])) {
                return response()->json(['message' => 'Each platform tier can only be sent once.'], 422);
            }

            $seen[$key] = true;
            $minimum = $this->catalog->minimumCents($price['platform'], $price['tier']);

            if ($minimum === null) {
                return response()->json(['message' => 'Unknown platform or tier.'], 422);
            }

            $name = config("billing.platforms.{$price['platform']}.name").' '
                .config("billing.platforms.{$price['platform']}.tiers.{$price['tier']}.name");
            $monthly = $price['list_price_cents'];
            $yearly = $price['yearly_price_cents'] ?? $current[$key]['yearly_price_cents'];

            if ($monthly < $minimum) {
                return response()->json([
                    'message' => $name.' cannot be priced below $'.number_format($minimum / 100, 2).' a month.',
                ], 422);
            }

            if ($yearly < $minimum * 12) {
                return response()->json([
                    'message' => $name.' yearly cannot be below $'.number_format($minimum * 12 / 100, 2).'.',
                ], 422);
            }

            if ($yearly >= $monthly * 12) {
                return response()->json([
                    'message' => $name.' yearly must cost less than 12 months at $'.number_format($monthly / 100, 2).'.',
                ], 422);
            }
        }

        DB::transaction(function () use ($validated, $current, $request) {
            foreach ($validated['prices'] as $price) {
                $key = $price['platform'].':'.$price['tier'];

                PlatformPrice::query()->updateOrCreate(
                    ['platform' => $price['platform'], 'tier' => $price['tier']],
                    [
                        'list_price_cents' => $price['list_price_cents'],
                        'yearly_price_cents' => $price['yearly_price_cents'] ?? $current[$key]['yearly_price_cents'],
                    ],
                );
            }

            if (array_key_exists('discount_percent', $validated)) {
                $this->settings->bulkUpdate(
                    [['key' => 'billing_bundle_discount_percent', 'value' => $validated['discount_percent']]],
                    $request->user()->id,
                );
            }
        });

        return $this->adminPrices();
    }

    /**
     * @return array{lines: array<int, array{platform: string, tier: string}>, interval: string}
     */
    private function validatedSelection(Request $request): array
    {
        $validated = $request->validate([
            'interval' => ['required', 'in:monthly,yearly'],
            'lines' => ['required', 'array', 'min:1', 'max:3'],
            'lines.*.platform' => ['required', 'string', 'in:x,tiktok,youtube'],
            'lines.*.tier' => ['required', 'string', 'in:starter,pro,agency'],
        ]);

        return [
            'interval' => $validated['interval'],
            'lines' => array_map(
                fn (array $line) => ['platform' => $line['platform'], 'tier' => $line['tier']],
                $validated['lines'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function createLines(BillingSubscription $subscription, array $quote): void
    {
        foreach ($quote['lines'] as $line) {
            BillingSubscriptionLine::query()->create([
                'billing_subscription_id' => $subscription->id,
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
    }

    private function findOwned(Request $request): ?BillingSubscription
    {
        $query = BillingSubscription::query()
            ->where('user_id', $request->user()->id)
            ->with('lines');

        if ($request->filled('id')) {
            return (clone $query)->whereKey($request->integer('id'))->first();
        }

        foreach (['active', 'past_due', 'trialing', 'trial_ended', 'ended'] as $status) {
            $found = (clone $query)->where('status', $status)->latest('id')->first();

            if ($found) {
                return $found;
            }
        }

        return (clone $query)->latest('id')->first();
    }

    /**
     * The mix the customer chose to move to when the current plan stops renewing.
     *
     * @return array<string, mixed>|null
     */
    private function nextPlan(User $user, ?BillingSubscription $subscription): ?array
    {
        if (! $subscription || ! is_array($subscription->next_plan)) {
            return null;
        }

        if (! in_array($subscription->status, ['active', 'past_due', 'ended'], true)) {
            return null;
        }

        $matrix = $this->catalog->matrix();

        return [
            'interval' => $subscription->next_plan['interval'],
            'starts_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'due' => $subscription->status === 'ended',
            'lines' => array_map(fn (array $line) => [
                'platform' => $line['platform'],
                'tier' => $line['tier'],
                'platform_name' => $matrix[$line['platform']]['name'] ?? $line['platform'],
                'tier_name' => $matrix[$line['platform']]['tiers'][$line['tier']]['name'] ?? $line['tier'],
            ], $subscription->next_plan['lines'] ?? []),
        ];
    }

    /**
     * @param  array{kind: string, current: ?BillingSubscription, credit_cents: int, charge_now_cents: int, effective_at: mixed}  $change
     * @return array<string, mixed>
     */
    private function presentChange(array $change): array
    {
        return [
            'kind' => $change['kind'],
            'credit_cents' => $change['credit_cents'],
            'charge_now_cents' => $change['charge_now_cents'],
            'effective_at' => $change['effective_at']?->toIso8601String(),
            'current' => $change['current'] ? $this->present($change['current']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BillingSubscription $subscription): array
    {
        $matrix = $this->catalog->matrix();

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'interval' => $subscription->interval,
            'currency' => $subscription->currency,
            'subtotal_cents' => $subscription->subtotal_cents,
            'discount_cents' => $subscription->discount_cents,
            'total_cents' => $subscription->total_cents,
            'first_charge_cents' => $subscription->firstChargeCents(),
            'credit_cents' => $subscription->credit_cents,
            'period' => $subscription->interval === 'yearly' ? '/yr' : '/mo',
            'renews' => (bool) $subscription->renews,
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'trial_days' => $subscription->trial_days,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'trial_days_left' => $subscription->status === 'trialing' && $subscription->trial_ends_at
                ? max(0, (int) ceil(now()->diffInHours($subscription->trial_ends_at, false) / 24))
                : null,
            'failure_reason' => $subscription->failure_reason,
            'lines' => $subscription->lines->map(function (BillingSubscriptionLine $line) use ($matrix) {
                $platform = $matrix[$line->platform] ?? null;
                $tier = $platform['tiers'][$line->tier] ?? null;

                return [
                    'platform' => $line->platform,
                    'platform_name' => $platform['name'] ?? $line->platform,
                    'tier' => $line->tier,
                    'tier_name' => $tier['name'] ?? $line->tier,
                    'list_cents' => $line->list_cents,
                    'charged_cents' => $line->charged_cents,
                    'discount_cents' => $line->discount_cents,
                    'is_anchor' => $line->is_anchor,
                    'accounts_included' => $line->accounts_included,
                    'monthly_allowance' => $line->monthly_allowance,
                    'allowance_unit' => $line->allowance_unit,
                ];
            })->values()->all(),
        ];
    }
}
