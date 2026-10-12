<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlutterwaveGateway
{
    /**
     * @param  array<string, mixed>  $quote
     * @return array{url: string, reference: string, subscription_id: ?string}
     */
    public function createCheckout(BillingSubscription $subscription, array $quote, User $user): array
    {
        // The plan holds the renewal price. The first payment can be lower when a plan change carries credit.
        $amount = number_format($quote['total_cents'] / 100, 2, '.', '');
        $firstAmount = number_format($subscription->firstChargeCents() / 100, 2, '.', '');
        $names = array_map(
            fn (array $line) => $line['platform_name'].' '.$line['tier_name'],
            $quote['lines'],
        );
        $description = 'Mackie17 · '.implode(', ', $names);
        $interval = $quote['interval'] === 'yearly' ? 'yearly' : 'monthly';

        $plan = Http::withToken($this->secret())
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.flutterwave.com/v3/payment-plans', [
                'amount' => $amount,
                'name' => Str::limit($description, 80, ''),
                'interval' => $interval,
                'currency' => 'USD',
            ]);

        if (! $plan->successful() || ! $plan->json('data.id')) {
            Log::warning('Flutterwave plan creation failed', [
                'status' => $plan->status(),
                'subscription_id' => $subscription->id,
            ]);

            throw new BillingGatewayException($this->errorMessage($plan->json(), 'Flutterwave could not start checkout.'));
        }

        $txRef = 'mackie17_'.$subscription->id.'_'.Str::lower(Str::random(8));
        $returnUrl = rtrim((string) config('billing.frontend_url'), '/')
            .'/billing/return?subscription='.$subscription->id;

        $payment = Http::withToken($this->secret())
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.flutterwave.com/v3/payments', [
                'tx_ref' => $txRef,
                'amount' => $firstAmount,
                'currency' => 'USD',
                'redirect_url' => $returnUrl,
                'payment_plan' => $plan->json('data.id'),
                'customer' => [
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'customizations' => [
                    'title' => 'Mackie17',
                    'description' => Str::limit($description, 120, ''),
                ],
                'meta' => [
                    'subscription_id' => $subscription->id,
                ],
            ]);

        $url = $payment->json('data.link');

        if (! $payment->successful() || ! is_string($url) || $url === '') {
            Log::warning('Flutterwave payment link failed', [
                'status' => $payment->status(),
                'subscription_id' => $subscription->id,
            ]);

            throw new BillingGatewayException($this->errorMessage($payment->json(), 'Flutterwave could not start checkout.'));
        }

        return [
            'url' => $url,
            'reference' => $txRef,
            'subscription_id' => (string) $plan->json('data.id'),
        ];
    }

    /**
     * @return array{paid: bool, gateway_subscription_id: ?string}
     */
    public function fetchPayment(BillingSubscription $subscription): array
    {
        if (! is_string($subscription->gateway_reference) || $subscription->gateway_reference === '') {
            return ['paid' => false, 'gateway_subscription_id' => null];
        }

        $response = Http::withToken($this->secret())
            ->acceptJson()
            ->timeout(20)
            ->get('https://api.flutterwave.com/v3/transactions/verify_by_reference', [
                'tx_ref' => $subscription->gateway_reference,
            ]);

        if (! $response->successful()) {
            throw new BillingGatewayException('Flutterwave could not confirm this payment yet.');
        }

        return $this->paymentFromPayload($response->json('data'), $subscription);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array{paid: bool, gateway_subscription_id: ?string}
     */
    public function paymentFromPayload(?array $data, BillingSubscription $subscription): array
    {
        if ($data === null || ($data['status'] ?? null) !== 'successful') {
            return ['paid' => false, 'gateway_subscription_id' => null];
        }

        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $paidCents = (int) round(((float) ($data['amount'] ?? 0)) * 100);

        if ($currency !== 'USD' || $paidCents < $subscription->firstChargeCents()) {
            Log::warning('Flutterwave payment did not match the quote', [
                'subscription_id' => $subscription->id,
                'paid_cents' => $paidCents,
                'expected' => $subscription->firstChargeCents(),
                'currency' => $currency,
            ]);

            return ['paid' => false, 'gateway_subscription_id' => null];
        }

        return [
            'paid' => true,
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
        ];
    }

    public function cancel(BillingSubscription $subscription): void
    {
        if (! is_string($subscription->gateway_subscription_id) || $subscription->gateway_subscription_id === '') {
            return;
        }

        $response = Http::withToken($this->secret())
            ->acceptJson()
            ->timeout(20)
            ->put('https://api.flutterwave.com/v3/payment-plans/'.$subscription->gateway_subscription_id.'/cancel');

        if (! $response->successful()) {
            throw new BillingGatewayException('Flutterwave could not cancel the previous subscription.');
        }
    }

    public function verifyWebhook(?string $header): bool
    {
        $secret = trim((string) config('billing.flutterwave.webhook_secret'));

        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        return hash_equals($secret, $header);
    }

    private function secret(): string
    {
        return trim((string) config('billing.flutterwave.secret_key'));
    }

    /**
     * @param  mixed  $payload
     */
    private function errorMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $message = $payload['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
