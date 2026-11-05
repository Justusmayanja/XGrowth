<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\GrowthAgent\SafetyEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccountSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_caps_follow_the_plan_not_the_old_loose_limits(): void
    {
        $user = $this->user();
        $this->connectX($user);
        $safety = app(SafetyEngineService::class);
        $status = $safety->status($user);

        $this->assertSame(app(ApiSpendService::class)->dailyPostTarget($user->id), $status['actions']['post']['daily_limit']);
        $this->assertSame(app(ApiSpendService::class)->dailyReplies($user->id), $status['actions']['reply']['daily_limit']);
        $this->assertSame(app(ApiSpendService::class)->dailyLikes($user->id), $status['actions']['like']['daily_limit']);
        $this->assertArrayNotHasKey('follow', $status['actions']);
        $this->assertFalse($safety->canAct($user, 'follow'));
    }

    public function test_a_rate_limit_from_x_freezes_the_account(): void
    {
        $user = $this->user();
        $account = $this->connectX($user);
        $safety = app(SafetyEngineService::class);

        $safety->noteXFailure($user, $account->id, 429, 'Too Many Requests');

        $this->assertFalse($safety->canAct($user, 'post', $account->id));
        $status = $safety->status($user, $account->id);
        $this->assertTrue($status['frozen']);
        $this->assertSame(25, $status['safety_score']);
        $this->assertStringContainsString('slow down', (string) $status['freeze_reason']);
        $this->assertStringContainsString('slow down', (string) $safety->postHoldReason($user, $account->id, true));
    }

    public function test_a_quiet_room_holds_a_normal_post_but_still_allows_a_spike(): void
    {
        $user = $this->user();
        $account = $this->connectX($user);

        foreach (['One', 'Two', 'Three'] as $i => $label) {
            Post::create([
                'user_id' => $user->id,
                'connected_account_id' => $account->id,
                'content' => $label,
                'content_type' => 'tweet',
                'status' => 'posted',
                'impressions' => 200,
                'replies' => 0,
                'posted_at' => now()->subHours(8 + $i),
            ]);
        }

        $safety = app(SafetyEngineService::class);
        $status = $safety->status($user, $account->id);

        $this->assertTrue($status['quiet_room']);
        $this->assertLessThanOrEqual(48, $status['safety_score']);
        $this->assertStringContainsString('no replies', (string) $safety->postHoldReason($user, $account->id, false));
        $this->assertNull($safety->postHoldReason($user, $account->id, true));
    }

    public function test_caps_are_counted_per_connected_account(): void
    {
        $user = $this->user();
        $first = $this->connectX($user, '11');
        $second = $this->connectX($user, '22', 'other');
        $safety = app(SafetyEngineService::class);
        $target = app(ApiSpendService::class)->dailyPostTarget($user->id);

        for ($i = 0; $i < $target; $i++) {
            $safety->recordAction($user, 'post', $first->id);
        }

        $this->assertFalse($safety->canAct($user, 'post', $first->id));
        $this->assertTrue($safety->canAct($user, 'post', $second->id));
    }

    public function test_a_freeze_is_stored_per_account(): void
    {
        Cache::flush();
        $user = $this->user();
        $first = $this->connectX($user, '11');
        $second = $this->connectX($user, '22', 'other');
        $safety = app(SafetyEngineService::class);

        $safety->noteXFailure($user, $first->id, 403, 'This account is temporarily locked');

        $this->assertTrue($safety->isFrozen($user, $first->id));
        $this->assertFalse($safety->isFrozen($user, $second->id));
        $this->assertTrue($safety->canAct($user, 'post', $second->id));
    }

    private function user(): User
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'x',
            'goal' => 'grow_followers',
            'custom_niche' => 'United',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'onboarding_completed' => true,
        ]);

        return $user->fresh();
    }

    private function connectX(User $user, string $accountId = '42', string $username = 'mackie'): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => $accountId,
            'username' => $username,
            'access_token' => 'user-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
    }
}
