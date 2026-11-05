<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\TrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeTrialTest extends TestCase
{
    use RefreshDatabase;

    private function setTrialPaused(bool $paused): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/platform-settings', [
            'settings' => [['key' => 'trial_paused', 'value' => $paused ? '1' : '0']],
        ])->assertOk();
    }

    private function setTrialDays(int $days): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/platform-settings', [
            'settings' => [['key' => 'trial_days', 'value' => (string) $days]],
        ])->assertOk();
    }

    private function pickPlatforms(User $user, array $platforms, array $tiers = []): void
    {
        $this->actingAs($user, 'sanctum')->postJson('/api/account/setup/platforms', [
            'platforms' => $platforms,
            'tiers' => $tiers,
        ])->assertOk();
    }

    public function test_picking_platforms_starts_a_seven_day_trial(): void
    {
        $this->setTrialPaused(false);
        $user = User::factory()->create();

        $this->pickPlatforms($user, ['x', 'tiktok'], ['tiktok' => 'pro']);

        $trial = BillingSubscription::query()->where('user_id', $user->id)->with('lines')->sole();
        $this->assertSame('trialing', $trial->status);
        $this->assertSame(7, $trial->trial_days);
        $this->assertTrue($trial->trial_ends_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertSame(['tiktok' => 'pro', 'x' => 'starter'], $trial->lines->pluck('tier', 'platform')->sortKeys()->all());
        $this->assertSame('pro', $user->fresh()->subscription_plan);

        $this->actingAs($user, 'sanctum')->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'trialing')
            ->assertJsonPath('subscription.trial_days_left', 7)
            ->assertJsonPath('trial_available', false);
    }

    public function test_changing_trial_length_only_affects_new_trials(): void
    {
        $existing = User::factory()->create();
        $this->pickPlatforms($existing, ['x']);

        $this->setTrialDays(30);

        $this->pickPlatforms($existing, ['x', 'youtube']);
        $newcomer = User::factory()->create();
        $this->pickPlatforms($newcomer, ['x']);

        $old = BillingSubscription::query()->where('user_id', $existing->id)->with('lines')->sole();
        $this->assertSame(7, $old->trial_days);
        $this->assertCount(2, $old->lines);

        $new = BillingSubscription::query()->where('user_id', $newcomer->id)->sole();
        $this->assertSame(30, $new->trial_days);
    }

    public function test_an_ended_trial_is_not_restarted_and_asks_to_upgrade(): void
    {
        $this->setTrialPaused(false);
        $user = User::factory()->create();
        $this->pickPlatforms($user, ['x'], ['x' => 'agency']);

        $this->travel(8)->days();
        $this->assertSame(1, app(TrialService::class)->expireDue());

        $this->pickPlatforms($user, ['x', 'tiktok']);

        $this->assertSame(1, BillingSubscription::query()->where('user_id', $user->id)->count());
        $this->assertSame('creator', $user->fresh()->subscription_plan);

        $this->actingAs($user, 'sanctum')->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'trial_ended')
            ->assertJsonPath('trial_available', false);
    }

    public function test_a_paused_trial_does_not_count_down_or_expire(): void
    {
        $user = User::factory()->create();
        $this->pickPlatforms($user, ['x']);

        $this->travel(8)->days();
        $this->assertSame(0, app(TrialService::class)->expireDue());

        $this->actingAs($user, 'sanctum')->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('trial_paused', true)
            ->assertJsonPath('subscription.status', 'trialing')
            ->assertJsonPath('subscription.trial_days_left', null)
            ->assertJsonPath('subscription.trial_ends_at', null);
    }
}
