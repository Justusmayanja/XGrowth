<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingSubscription;
use App\Services\Billing\BillingLifecycle;
use App\Services\Billing\FlutterwaveGateway;
use App\Services\Billing\PaddleGateway;
use App\Services\Billing\SubscriptionActivator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingWebhookController extends Controller
{
    public function __construct(
        private readonly SubscriptionActivator $activator,
        private readonly BillingLifecycle $lifecycle,
    ) {}

    public function paddle(Request $request, PaddleGateway $paddle): JsonResponse
    {
        $raw = $request->getContent();

        if (! $paddle->verifyWebhook($raw, $request->header('Paddle-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['ok' => true, 'ignored' => 'payload']);
        }

        $event = (string) ($payload['event_type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (str_starts_with($event, 'subscription.')) {
            $this->lifecycle->applyPaddleSubscription($event, $data);

            return response()->json(['ok' => true]);
        }

        if (! in_array($event, ['transaction.completed', 'transaction.paid'], true)) {
            return response()->json(['ok' => true, 'ignored' => 'event']);
        }

        $subscription = $this->findPaddleSubscription($data);

        if ($subscription === null) {
            Log::warning('Paddle webhook did not match a subscription', ['event' => $event]);

            return response()->json(['ok' => true, 'ignored' => 'subscription']);
        }

        $subtotal = $data['details']['totals']['subtotal'] ?? null;

        if ($subtotal !== null && (int) $subtotal < $subscription->total_cents) {
            Log::warning('Paddle webhook amount was below the quote', [
                'subscription_id' => $subscription->id,
                'subtotal' => $subtotal,
            ]);

            return response()->json(['ok' => true, 'ignored' => 'amount']);
        }

        $remoteId = $data['subscription_id'] ?? null;

        $this->activator->activate(
            $subscription,
            is_string($remoteId) ? $remoteId : null,
            isset($payload['event_id']) ? (string) $payload['event_id'] : $event,
        );

        return response()->json(['ok' => true]);
    }

    public function flutterwave(Request $request, FlutterwaveGateway $flutterwave): JsonResponse
    {
        if (! $flutterwave->verifyWebhook($request->header('verif-hash'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($event === 'subscription.cancelled') {
            $planId = $data['plan']['id'] ?? null;

            if ($planId !== null) {
                $this->lifecycle->applyFlutterwaveCancellation((string) $planId);
            }

            return response()->json(['ok' => true]);
        }

        if ($event !== 'charge.completed') {
            return response()->json(['ok' => true, 'ignored' => 'event']);
        }

        $subscription = $this->findFlutterwaveSubscription($data);

        if ($subscription === null) {
            $renewed = $this->lifecycle->applyFlutterwaveRenewal($data);

            return response()->json(['ok' => true, 'ignored' => $renewed ? null : 'subscription']);
        }

        $payment = $flutterwave->paymentFromPayload($data, $subscription);

        if (! $payment['paid']) {
            return response()->json(['ok' => true, 'ignored' => 'payment']);
        }

        $this->activator->activate(
            $subscription,
            $payment['gateway_subscription_id'],
            isset($data['id']) ? (string) $data['id'] : 'charge.completed',
        );

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findPaddleSubscription(array $data): ?BillingSubscription
    {
        $transactionId = isset($data['id']) ? (string) $data['id'] : '';
        $customId = $data['custom_data']['subscription_id'] ?? null;
        $subscription = null;

        if ($transactionId !== '') {
            $subscription = BillingSubscription::query()->where('gateway_reference', $transactionId)->first();
        }

        if ($subscription === null && $customId !== null) {
            $subscription = BillingSubscription::query()->find($customId);
        }

        if (
            $subscription !== null
            && $transactionId !== ''
            && is_string($subscription->gateway_reference)
            && $subscription->gateway_reference !== ''
            && $subscription->gateway_reference !== $transactionId
        ) {
            return null;
        }

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findFlutterwaveSubscription(array $data): ?BillingSubscription
    {
        $txRef = isset($data['tx_ref']) ? (string) $data['tx_ref'] : '';
        $metaId = $data['meta']['subscription_id'] ?? null;
        $subscription = null;

        if ($txRef !== '') {
            $subscription = BillingSubscription::query()->where('gateway_reference', $txRef)->first();
        }

        if ($subscription === null && $metaId !== null) {
            $subscription = BillingSubscription::query()->find($metaId);
        }

        if (
            $subscription !== null
            && $txRef !== ''
            && is_string($subscription->gateway_reference)
            && $subscription->gateway_reference !== ''
            && $subscription->gateway_reference !== $txRef
        ) {
            return null;
        }

        return $subscription;
    }
}
