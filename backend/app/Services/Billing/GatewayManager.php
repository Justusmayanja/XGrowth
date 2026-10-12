<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class GatewayManager
{
    public function __construct(
        private readonly PaddleGateway $paddle,
        private readonly FlutterwaveGateway $flutterwave,
    ) {}

    public function driver(): string
    {
        $driver = strtolower(trim((string) config('billing.gateway', 'paddle')));

        return in_array($driver, ['paddle', 'flutterwave'], true) ? $driver : '';
    }

    public function isConfigured(): bool
    {
        return $this->missingKeys() === [];
    }

    /**
     * @return array<int, string>
     */
    public function missingKeys(): array
    {
        $driver = $this->driver();

        if ($driver === '') {
            return ['BILLING_GATEWAY'];
        }

        $keys = $driver === 'paddle'
            ? [
                'PADDLE_API_KEY' => config('billing.paddle.api_key'),
                'PADDLE_WEBHOOK_SECRET' => config('billing.paddle.webhook_secret'),
            ]
            : [
                'FLUTTERWAVE_SECRET_KEY' => config('billing.flutterwave.secret_key'),
                'FLUTTERWAVE_WEBHOOK_SECRET' => config('billing.flutterwave.webhook_secret'),
            ];

        $missing = [];

        foreach ($keys as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array{url: string, reference: string, subscription_id: ?string}
     */
    public function createCheckout(BillingSubscription $subscription, array $quote, User $user): array
    {
        return $this->driver() === 'flutterwave'
            ? $this->flutterwave->createCheckout($subscription, $quote, $user)
            : $this->paddle->createCheckout($subscription, $quote, $user);
    }

    /**
     * @return array{paid: bool, gateway_subscription_id: ?string}
     */
    public function fetchPayment(BillingSubscription $subscription): array
    {
        return $subscription->gateway === 'flutterwave'
            ? $this->flutterwave->fetchPayment($subscription)
            : $this->paddle->fetchPayment($subscription);
    }

    /**
     * Stops the gateway from charging this subscription again. With $atPeriodEnd the
     * customer keeps what they paid for until the current period ends.
     */
    public function cancelRemote(BillingSubscription $subscription, bool $atPeriodEnd = false): void
    {
        try {
            if ($subscription->gateway === 'flutterwave') {
                $this->flutterwave->cancel($subscription);
            } elseif ($subscription->gateway === 'paddle') {
                $this->paddle->cancel($subscription, $atPeriodEnd);
            }
        } catch (BillingGatewayException $exception) {
            Log::error('Could not cancel the previous gateway subscription', [
                'subscription_id' => $subscription->id,
                'gateway' => $subscription->gateway,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
