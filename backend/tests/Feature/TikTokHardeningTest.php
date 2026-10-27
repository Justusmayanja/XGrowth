<?php

namespace Tests\Feature;

use App\Exceptions\TikTokActionException;
use App\Models\AccountProfile;
use App\Models\BillingSubscription;
use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\TikTok\TikTokEngineService;
use App\Support\TikTokCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class TikTokHardeningTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openai.api_key' => '']);
        Http::preventStrayRequests();
    }

    public function test_a_suggested_time_in_the_past_can_still_be_confirmed(): void
    {
        $user = $this->readyUser();
        $clip = TikTokClip::factory()->waiting()->create([
            'user_id' => $user->id,
            'suggested_for' => now()->subDay(),
        ]);

        $queued = app(TikTokEngineService::class)->confirm($user, $clip);

        $this->assertSame(TikTokCatalog::QUEUED, $queued->status);
        $this->assertNotNull($queued->confirmed_at);
        $this->assertFalse($queued->package['render']['ready']);
        $this->assertFalse($queued->package['publish']['uploaded']);
    }

    public function test_confirm_and_results_lose_to_a_discard_that_landed_first(): void
    {
        $user = $this->readyUser();
        $engine = app(TikTokEngineService::class);
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);

        $engine->discard($user, $clip);
        try {
            $engine->confirm($user, $clip->fresh());
            $this->fail('Confirm should see the discard.');
        } catch (TikTokActionException $e) {
            $this->assertSame(TikTokCatalog::ERROR_INVALID_STATE, $e->errorCode);
        }
        $clip->refresh();
        $this->assertSame(TikTokCatalog::DISCARDED, $clip->status);
        $this->assertNull($clip->confirmed_at);

        $queued = TikTokClip::factory()->queued()->create(['user_id' => $user->id]);
        $engine->discard($user, $queued);
        try {
            $engine->recordResults($user, $queued->fresh(), [
                'views' => 10,
                'completion_percent' => 40,
                'follows' => 1,
            ]);
            $this->fail('Results should see the discard.');
        } catch (TikTokActionException $e) {
            $this->assertSame(TikTokCatalog::ERROR_INVALID_STATE, $e->errorCode);
        }
        $this->assertNull($queued->fresh()->learned_at);
    }

    public function test_two_runs_cannot_both_hold_the_lock(): void
    {
        $user = $this->readyUser();
        $lock = Cache::lock('tiktok-run-'.$user->id, 30);
        $this->assertTrue($lock->get());

        $result = app(TikTokEngineService::class)->run($user);
        $lock->release();

        $this->assertSame(TikTokCatalog::BUSY, $result['reason']);
        $this->assertSame(0, TikTokClip::query()->where('user_id', $user->id)->count());
    }

    public function test_last_period_and_yearly_slices_do_not_spend_this_month(): void
    {
        $monthly = $this->readyUser(allowance: 1);
        TikTokClip::factory()->learned()->create([
            'user_id' => $monthly->id,
            'queued_at' => now()->subDays(40),
            'confirmed_at' => now()->subDays(40),
        ]);
        $created = app(TikTokEngineService::class)->run($monthly);
        $this->assertSame(TikTokCatalog::CREATED, $created['outcome']);

        $yearly = $this->readyUser(allowance: 1);
        $start = now()->utc()->subDays(40);
        BillingSubscription::query()->where('user_id', $yearly->id)->update([
            'interval' => 'yearly',
            'current_period_starts_at' => $start,
            'current_period_ends_at' => $start->copy()->addYear(),
        ]);
        TikTokClip::factory()->learned()->create([
            'user_id' => $yearly->id,
            'queued_at' => $start->copy()->addDay(),
            'confirmed_at' => $start->copy()->addDay(),
        ]);
        $again = app(TikTokEngineService::class)->run($yearly->fresh());
        $this->assertSame(TikTokCatalog::CREATED, $again['outcome']);
    }

    public function test_an_expired_trial_and_a_canceled_plan_keep_queued_clips_but_stop_new_ones(): void
    {
        $trial = $this->readyUser();
        $queued = TikTokClip::factory()->queued()->create(['user_id' => $trial->id]);
        BillingSubscription::query()->where('user_id', $trial->id)->update([
            'status' => 'trialing',
            'trial_ends_at' => now()->subMinute(),
            'current_period_starts_at' => null,
            'current_period_ends_at' => null,
        ]);
        $queued->delete();
        $skipped = app(TikTokEngineService::class)->run($trial->fresh());
        $this->assertSame(TikTokCatalog::NO_PLAN, $skipped['reason']);

        $kept = TikTokClip::factory()->queued()->create(['user_id' => $trial->id]);
        $this->assertSame(TikTokCatalog::QUEUED, $kept->fresh()->status);

        $canceled = $this->readyUser();
        $still = TikTokClip::factory()->queued()->create(['user_id' => $canceled->id]);
        BillingSubscription::query()->where('user_id', $canceled->id)->update([
            'renews' => false,
            'canceled_at' => now(),
            'current_period_ends_at' => now()->addDay(),
        ]);
        $this->assertSame(TikTokCatalog::OPEN_CLIP, app(TikTokEngineService::class)->diagnose($canceled->fresh())['reason']);
        $still->delete();
        $this->assertTrue(app(TikTokEngineService::class)->diagnose($canceled->fresh())['can_run']);

        BillingSubscription::query()->where('user_id', $canceled->id)->update([
            'current_period_ends_at' => now()->subMinute(),
        ]);
        $after = app(TikTokEngineService::class)->run($canceled->fresh());
        $this->assertSame(TikTokCatalog::NO_PLAN, $after['reason']);
        $this->assertSame(0, TikTokClip::query()->where('user_id', $canceled->id)->count());
    }

    public function test_a_blocked_user_cannot_confirm_and_is_left_out_of_run_all(): void
    {
        $user = $this->readyUser();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $user->update(['is_blocked' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
            ->assertForbidden();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/run')
            ->assertForbidden();
        $this->assertSame(TikTokCatalog::WAITING, $clip->fresh()->status);

        $summary = app(TikTokEngineService::class)->runAll();
        $this->assertSame(0, $summary['created']);
        $this->assertArrayNotHasKey(TikTokCatalog::BLOCKED, $summary['skipped']);
    }

    private function readyUser(bool|int $allowance = 30): User
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'tiktok',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'timezone' => 'Africa/Kampala',
            'onboarding_completed' => true,
            'discovery' => ['presence' => 'faceless'],
        ]);
        PlatformSetup::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'goals' => 'for_you',
            'settings' => ['publish' => 'confirm'],
            'completed_at' => now(),
        ]);
        if ($allowance !== false) {
            $this->giveTikTokAllowance($user, is_int($allowance) ? $allowance : 30);
        }

        return $user->fresh('accountProfile');
    }
}
