<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaddleGateway
{
    /**
     * @param  array<string, mixed>  $quote
     * @return array{url: string, reference: string, subscription_id: ?string}
     */
    public function createCheckout(BillingSubscription $subscription, array $quote, User $user): array
    {
        $items = [];

        foreach ($quote['lines'] as $line) {
            $description = $line['platform_name'].' · '.$line['tier_name'];
            $price = [
                'description' => $description,
                'unit_price' => [
                    'amount' => (string) $line['charged_cents'],
                    'currency_code' => 'USD',
                ],
                'billing_cycle' => [
                    'interval' => $quote['interval'] === 'yearly' ? 'year' : 'month',
                    'frequency' => 1,
                ],
            ];

            $productId = config('billing.paddle.product_id');

            if (is_string($productId) && trim($productId) !== '') {
                $price['product_id'] = $productId;
            } else {
                $price['product'] = [
                    'name' => 'Mackie17 '.$line['platform_name'],
                    'tax_category' => 'saas',
                    'description' => $description,
                ];
            }

            $items[] = [
                'quantity' => 1,
                'price' => $price,
            ];
        }

        $payload = [
            'items' => $items,
            'collection_mode' => 'automatic',
            'currency_code' => 'USD',
            'custom_data' => [
                'subscription_id' => (string) $subscription->id,
                'user_id' => (string) $user->id,
            ],
        ];

        $credit = $subscription->total_cents - $subscription->firstChargeCents();

        if ($credit > 0) {
            $payload['discount'] = [
                'type' => 'flat',
                'amount' => (string) $credit,
                'description' => 'Credit for unused time on the previous plan',
                'recur' => false,
            ];
        }

        $response = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/transactions', $payload);

        if (! $response->successful()) {
            Log::warning('Paddle checkout failed', [
                'status' => $response->status(),
                'subscription_id' => $subscription->id,
            ]);

            throw new BillingGatewayException($this->errorMessage($response->json(), 'Paddle could not start checkout.'));
        }

        $url = $response->json('data.checkout.url');
        $reference = $response->json('data.id');

        if (! is_string($url) || $url === '' || ! is_string($reference) || $reference === '') {
            throw new BillingGatewayException('Paddle did not return a checkout link.');
        }

        return [
            'url' => $url,
            'reference' => $reference,
            'subscription_id' => null,
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

        $response = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl().'/transactions/'.$subscription->gateway_reference);

        if (! $response->successful()) {
            throw new BillingGatewayException('Paddle could not confirm this payment yet.');
        }

        $status = (string) $response->json('data.status');

        if (! in_array($status, ['paid', 'completed'], true)) {
            return ['paid' => false, 'gateway_subscription_id' => null];
        }

        $subtotal = $response->json('data.details.totals.subtotal');

        if ($subtotal !== null && (int) $subtotal < $subscription->total_cents) {
            Log::warning('Paddle payment was below the quoted total', [
                'subscription_id' => $subscription->id,
                'subtotal' => $subtotal,
                'expected' => $subscription->total_cents,
            ]);

            return ['paid' => false, 'gateway_subscription_id' => null];
        }

        $remoteId = $response->json('data.subscription_id');

        return [
            'paid' => true,
            'gateway_subscription_id' => is_string($remoteId) && $remoteId !== '' ? $remoteId : null,
        ];
    }

    public function cancel(BillingSubscription $subscription, bool $atPeriodEnd = false): void
    {
        if (! is_string($subscription->gateway_subscription_id) || $subscription->gateway_subscription_id === '') {
            return;
        }

        $response = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/subscriptions/'.$subscription->gateway_subscription_id.'/cancel', [
                'effective_from' => $atPeriodEnd ? 'next_billing_period' : 'immediately',
            ]);

        if (! $response->successful()) {
            throw new BillingGatewayException('Paddle could not cancel the previous subscription.');
        }
    }

    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = trim((string) config('billing.paddle.webhook_secret'));

        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        if (! preg_match('/(?:^|;)ts=(\d+)/', $signatureHeader, $tsMatch)) {
            return false;
        }

        if (! preg_match('/(?:^|;)h1=([a-f0-9]+)/', $signatureHeader, $hashMatch)) {
            return false;
        }

        $timestamp = (int) $tsMatch[1];
        $tolerance = (int) config('billing.webhook_tolerance_seconds', 300);

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$rawBody, $secret);

        return hash_equals($expected, $hashMatch[1]);
    }

    public static function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $hash = hash_hmac('sha256', $timestamp.':'.$body, $secret);

        return 'ts='.$timestamp.';h1='.$hash;
    }

    private function baseUrl(): string
    {
        return config('billing.paddle.environment') === 'live'
            ? 'https://api.paddle.com'
            : 'https://sandbox-api.paddle.com';
    }

    private function apiKey(): string
    {
        return trim((string) config('billing.paddle.api_key'));
    }

    /**
     * @param  mixed  $payload
     */
    private function errorMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $detail = $payload['error']['detail'] ?? $payload['error']['message'] ?? null;

        return is_string($detail) && $detail !== '' ? $detail : $fallback;
    }
}
