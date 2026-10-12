<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionLine;
use App\Models\User;
use App\Services\Billing\BillingLifecycle;
use App\Services\Billing\PaddleGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlanChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.gateway' => 'paddle',
            'billing.paddle.api_key' => 'pdl_test',
            'billing.paddle.webhook_secret' => 'whsec_test',
            'billing.paddle.environment' => 'sandbox',
        ]);

        Http::fake([
            'https://sandbox-api.paddle.com/transactions' => Http::response([
                'data' => ['id' => 'txn_new', 'checkout' => ['url' => 'https://pay.test/new']],
            ], 201),
            'https://sandbox-api.paddle.com/subscriptions/*' => Http::response(['data' => []], 200),
        ]);
    }

    /**
     * An X Starter monthly plan halfway through its month.
     */
    private function payingUser(string $interval = 'monthly', int $total = 3200, string $tier = 'starter'): array
    {
        $user = User::factory()->create(['subscription_plan' => 'creator']);
        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'interval' => $interval,
            'currency' => 'USD',
            'subtotal_cents' => $total,
            'discount_cents' => 0,
            'total_cents' => $total,
            'gateway' => 'paddle',
            'gateway_reference' => 'txn_old',
            'gateway_subscription_id' => 'sub_old',
            'activated_at' => now()->subDays(15),
            'current_period_starts_at' => now()->subDays(15),
            'current_period_ends_at' => now()->addDays(15),
        ]);

        BillingSubscriptionLine::query()->create([
            'billing_subscription_id' => $subscription->id,
            'platform' => 'x',
            'tier' => $tier,
            'list_cents' => $total,
            'charged_cents' => $total,
            'discount_cents' => 0,
            'is_anchor' => true,
            'accounts_included' => 1,
            'monthly_allowance' => 40,
            'allowance_unit' => 'posts',
        ]);

        return [$user, $subscription];
    }

    private function preview(User $user, array $lines, string $interval = 'monthly')
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/billing/preview', [
            'interval' => $interval,
            'lines' => $lines,
        ]);
    }

    private function checkout(User $user, array $lines, string $interval = 'monthly')
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/billing/checkout', [
            'interval' => $interval,
            'lines' => $lines,
        ]);
    }

    public function test_adding_a_platform_mid_period_charges_only_the_difference(): void
    {
        [$user] = $this->payingUser();
        $mix = [['platform' => 'x', 'tier' => 'starter'], ['platform' => 'tiktok', 'tier' => 'starter']];

        $preview = $this->preview($user, $mix)->assertOk();
        $this->assertSame('upgrade', $preview->json('change.kind'));
        $credit = $preview->json('change.credit_cents');
        $this->assertEqualsWithDelta(1600, $credit, 10);
        $this->assertSame($preview->json('total_cents') - $credit, $preview->json('change.charge_now_cents'));

        $this->checkout($user, $mix)->assertOk();

        $pending = BillingSubscription::query()->where('status', 'pending')->sole();
        $this->assertSame($preview->json('change.charge_now_cents'), $pending->first_charge_cents);

        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox-api.paddle.com/transactions'
            && $request['discount']['type'] === 'flat'
            && (int) $request['discount']['amount'] === $pending->total_cents - $pending->first_charge_cents);
    }

    public function test_picking_the_current_plan_is_rejected(): void
    {
        [$user] = $this->payingUser();

        $this->checkout($user, [['platform' => 'x', 'tier' => 'starter']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'same_plan');
    }

    public function test_downgrade_waits_for_the_end_of_the_paid_period(): void
    {
        [$user, $current] = $this->payingUser('monthly', 4900, 'pro');

        $response = $this->checkout($user, [['platform' => 'x', 'tier' => 'starter']])->assertOk();

        $response->assertJsonPath('scheduled', true);
        $current->refresh();
        $this->assertSame('active', $current->status);
        $this->assertFalse($current->renews);
        $this->assertSame([['platform' => 'x', 'tier' => 'starter']], $current->next_plan['lines']);
        $this->assertSame(0, BillingSubscription::query()->where('status', 'pending')->count());

        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox-api.paddle.com/subscriptions/sub_old/cancel'
            && $request['effective_from'] === 'next_billing_period');

        $this->travel(16)->days();
        app(BillingLifecycle::class)->sweep();

        $this->assertSame('ended', $current->fresh()->status);
        $this->actingAs($user, 'sanctum')->getJson('/api/billing/subscription')
            ->assertJsonPath('next_plan.due', true)
            ->assertJsonPath('next_plan.lines.0.tier', 'starter');
    }

    public function test_yearly_to_monthly_is_scheduled_and_monthly_to_yearly_is_immediate(): void
    {
        [$yearly] = $this->payingUser('yearly', 32000);
        $this->assertSame('downgrade', $this->preview($yearly, [['platform' => 'x', 'tier' => 'starter']])->json('change.kind'));

        [$monthly] = $this->payingUser();
        $preview = $this->preview($monthly, [['platform' => 'x', 'tier' => 'starter']], 'yearly');
        $this->assertSame('upgrade', $preview->json('change.kind'));
        $this->assertGreaterThan(0, $preview->json('change.credit_cents'));
    }

    public function test_upgrade_replaces_the_old_plan_and_cancels_it_immediately(): void
    {
        [$user, $old] = $this->payingUser();
        $this->checkout($user, [['platform' => 'x', 'tier' => 'pro']])->assertOk();
        $new = BillingSubscription::query()->where('status', 'pending')->sole();

        $this->paddleWebhook([
            'event_id' => 'evt_up',
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_new',
                'subscription_id' => 'sub_new',
                'custom_data' => ['subscription_id' => (string) $new->id],
                'details' => ['totals' => ['subtotal' => (string) $new->total_cents]],
            ],
        ])->assertOk();

        $this->assertSame('active', $new->fresh()->status);
        $this->assertSame('replaced', $old->fresh()->status);
        $this->assertSame('pro', $user->fresh()->subscription_plan);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox-api.paddle.com/subscriptions/sub_old/cancel'
            && $request['effective_from'] === 'immediately');
    }

    public function test_paying_an_old_checkout_after_a_newer_one_is_flagged_not_activated(): void
    {
        $user = User::factory()->create();
        $this->checkout($user, [['platform' => 'x', 'tier' => 'starter']])->assertOk();
        $first = BillingSubscription::query()->where('status', 'pending')->sole();
        $first->update(['gateway_reference' => 'txn_first']);

        $this->checkout($user, [['platform' => 'x', 'tier' => 'pro']])->assertOk();
        $second = BillingSubscription::query()->where('status', 'pending')->sole();
        $this->assertSame('canceled', $first->fresh()->status);

        $this->paddleWebhook($this->paid($second, 'txn_new', 'sub_second'))->assertOk();
        $this->paddleWebhook($this->paid($first, 'txn_first', 'sub_first'))->assertOk();

        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame('duplicate_payment', $first->fresh()->status);
    }

    public function test_paddle_renewal_moves_the_period_and_cancellation_ends_the_plan(): void
    {
        [$user, $current] = $this->payingUser();
        $end = now()->addDays(45)->startOfSecond();

        $this->paddleWebhook([
            'event_id' => 'evt_renew',
            'event_type' => 'subscription.updated',
            'data' => [
                'id' => 'sub_old',
                'status' => 'active',
                'current_billing_period' => ['starts_at' => now()->addDays(15)->toIso8601String(), 'ends_at' => $end->toIso8601String()],
            ],
        ])->assertOk();

        $this->assertTrue($current->fresh()->current_period_ends_at->equalTo($end));

        $this->paddleWebhook([
            'event_id' => 'evt_due',
            'event_type' => 'subscription.past_due',
            'data' => ['id' => 'sub_old', 'status' => 'past_due'],
        ])->assertOk();
        $this->assertSame('past_due', $current->fresh()->status);

        $this->travel(50)->days();
        $this->paddleWebhook([
            'event_id' => 'evt_cancel',
            'event_type' => 'subscription.canceled',
            'data' => ['id' => 'sub_old', 'status' => 'canceled'],
        ])->assertOk();

        $this->assertSame('ended', $current->fresh()->status);
        $this->assertSame('creator', $user->fresh()->subscription_plan);
    }

    public function test_cancel_keeps_access_until_the_period_ends(): void
    {
        [$user, $current] = $this->payingUser('monthly', 4900, 'pro');
        $user->update(['subscription_plan' => 'pro']);

        $this->actingAs($user, 'sanctum')->postJson('/api/billing/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.renews', false);

        app(BillingLifecycle::class)->sweep();
        $this->assertSame('active', $current->fresh()->status);
        $this->assertSame('pro', $user->fresh()->subscription_plan);

        $this->travel(16)->days();
        app(BillingLifecycle::class)->sweep();
        $this->assertSame('ended', $current->fresh()->status);
        $this->assertSame('creator', $user->fresh()->subscription_plan);
    }

    public function test_admin_yearly_price_must_be_discounted_and_feeds_the_quote(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $price = fn (int $yearly) => [
            'discount_percent' => 20,
            'prices' => [['platform' => 'x', 'tier' => 'starter', 'list_price_cents' => 3200, 'yearly_price_cents' => $yearly]],
        ];

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/billing/prices', $price(38400))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/billing/prices', $price(29000))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/billing/prices', $price(30720))
            ->assertOk()
            ->assertJsonPath('discount_percent', 20);

        $this->postJson('/api/billing/quote', [
            'interval' => 'yearly',
            'lines' => [['platform' => 'x', 'tier' => 'starter']],
        ])->assertOk()
            ->assertJsonPath('total_cents', 30720)
            ->assertJsonPath('discount_cents', 38400 - 30720);

        $this->postJson('/api/billing/quote', [
            'interval' => 'monthly',
            'lines' => [['platform' => 'x', 'tier' => 'starter'], ['platform' => 'tiktok', 'tier' => 'pro']],
        ])->assertOk()->assertJsonPath('lines.0.charged_cents', 2560);
    }

    public function test_flutterwave_renewal_extends_the_period_once_and_cancellation_stops_renewal(): void
    {
        config(['billing.flutterwave.webhook_secret' => 'flw_hash']);
        [$user, $current] = $this->payingUser();
        $current->update(['gateway' => 'flutterwave', 'gateway_reference' => 'mackie17_1_abc', 'gateway_subscription_id' => '777']);
        $end = $current->current_period_ends_at;

        $charge = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 9001,
                'tx_ref' => 'flw-recurring-1',
                'amount' => 32,
                'currency' => 'USD',
                'status' => 'successful',
                'customer' => ['email' => strtoupper($user->email)],
            ],
        ];
        $headers = ['verif-hash' => 'flw_hash'];

        $this->postJson('/api/billing/webhooks/flutterwave', $charge, $headers)->assertOk();
        $this->postJson('/api/billing/webhooks/flutterwave', $charge, $headers)->assertOk();

        $this->assertTrue($current->fresh()->current_period_ends_at->equalTo($end->copy()->addMonth()));

        $this->postJson('/api/billing/webhooks/flutterwave', [
            'event' => 'subscription.cancelled',
            'data' => ['plan' => ['id' => 777]],
        ], $headers)->assertOk();

        $this->assertFalse($current->fresh()->renews);
        $this->assertSame('active', $current->fresh()->status);
    }

    private function paid(BillingSubscription $subscription, string $txn, string $sub): array
    {
        return [
            'event_id' => 'evt_'.$txn,
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => $txn,
                'subscription_id' => $sub,
                'custom_data' => ['subscription_id' => (string) $subscription->id],
                'details' => ['totals' => ['subtotal' => (string) $subscription->total_cents]],
            ],
        ];
    }

    private function paddleWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/billing/webhooks/paddle', [], [], [], [
            'HTTP_PADDLE_SIGNATURE' => PaddleGateway::sign($body, 'whsec_test'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }
}
