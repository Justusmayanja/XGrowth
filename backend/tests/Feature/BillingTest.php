<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\PaddleGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_prices_a_mixed_cart_from_the_catalog(): void
    {
        $response = $this->postJson('/api/billing/quote', [
            'interval' => 'monthly',
            'lines' => [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'pro'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('total_cents', 10620)
            ->assertJsonPath('discount_cents', 480)
            ->assertJsonPath('lines.0.charged_cents', 2720)
            ->assertJsonPath('lines.1.charged_cents', 7900);
    }

    public function test_checkout_reports_the_missing_gateway_keys(): void
    {
        config([
            'billing.gateway' => 'paddle',
            'billing.paddle.api_key' => '',
            'billing.paddle.webhook_secret' => '',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/billing/checkout', [
            'interval' => 'monthly',
            'lines' => [
                ['platform' => 'x', 'tier' => 'starter'],
            ],
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'gateway_not_configured')
            ->assertJsonPath('missing.0', 'PADDLE_API_KEY')
            ->assertJsonPath('missing.1', 'PADDLE_WEBHOOK_SECRET');
    }

    public function test_paddle_checkout_and_webhook_activate_the_highest_tier(): void
    {
        config([
            'billing.gateway' => 'paddle',
            'billing.paddle.api_key' => 'pdl_test',
            'billing.paddle.webhook_secret' => 'whsec_test',
            'billing.paddle.environment' => 'sandbox',
        ]);

        Http::fake([
            'https://sandbox-api.paddle.com/transactions' => Http::response([
                'data' => [
                    'id' => 'txn_test_1',
                    'checkout' => ['url' => 'https://checkout.paddle.com/pay/test'],
                ],
            ], 201),
            'https://sandbox-api.paddle.com/subscriptions/sub_old/cancel' => Http::response(['data' => ['id' => 'sub_old']], 200),
        ]);

        $user = User::factory()->create();
        $previous = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'interval' => 'monthly',
            'currency' => 'USD',
            'subtotal_cents' => 3200,
            'discount_cents' => 0,
            'total_cents' => 3200,
            'gateway' => 'paddle',
            'gateway_reference' => 'txn_old',
            'gateway_subscription_id' => 'sub_old',
        ]);

        $checkout = $this->actingAs($user, 'sanctum')->postJson('/api/billing/checkout', [
            'interval' => 'monthly',
            'lines' => [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'pro'],
            ],
        ]);

        $checkout->assertOk()->assertJsonPath('checkout_url', 'https://checkout.paddle.com/pay/test');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://sandbox-api.paddle.com/transactions') {
                return false;
            }

            $amounts = array_map(
                fn (array $item) => (int) $item['price']['unit_price']['amount'],
                $request['items'],
            );
            sort($amounts);

            return $amounts === [2720, 7900];
        });

        $subscriptionId = $checkout->json('subscription_id');
        $body = json_encode([
            'event_id' => 'evt_1',
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_test_1',
                'status' => 'completed',
                'subscription_id' => 'sub_new',
                'custom_data' => ['subscription_id' => (string) $subscriptionId],
                'details' => ['totals' => ['subtotal' => '10620']],
            ],
        ], JSON_THROW_ON_ERROR);

        $webhook = $this->call(
            'POST',
            '/api/billing/webhooks/paddle',
            [],
            [],
            [],
            [
                'HTTP_PADDLE_SIGNATURE' => PaddleGateway::sign($body, 'whsec_test'),
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $body,
        );

        $webhook->assertOk();
        $this->assertSame('pro', $user->fresh()->subscription_plan);
        $this->assertSame('active', BillingSubscription::query()->find($subscriptionId)->status);
        $this->assertSame('replaced', $previous->fresh()->status);
        $this->assertSame('sub_new', BillingSubscription::query()->find($subscriptionId)->gateway_subscription_id);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://sandbox-api.paddle.com/subscriptions/sub_old/cancel';
        });
    }

    public function test_paddle_webhook_rejects_a_bad_signature(): void
    {
        config(['billing.paddle.webhook_secret' => 'whsec_test']);

        $this->call(
            'POST',
            '/api/billing/webhooks/paddle',
            [],
            [],
            [],
            [
                'HTTP_PADDLE_SIGNATURE' => 'ts=1;h1=deadbeef',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '{"event_type":"transaction.completed"}',
        )->assertStatus(401);
    }

    public function test_admin_cannot_price_a_platform_below_its_minimum(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/billing/prices', [
            'prices' => [
                ['platform' => 'x', 'tier' => 'starter', 'list_price_cents' => 2000],
            ],
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/billing/prices', [
            'prices' => [
                ['platform' => 'x', 'tier' => 'starter', 'list_price_cents' => 3500],
            ],
        ])->assertOk();

        $this->postJson('/api/billing/quote', [
            'interval' => 'monthly',
            'lines' => [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'pro'],
            ],
        ])->assertOk()->assertJsonPath('lines.0.charged_cents', 2975);
    }
}
