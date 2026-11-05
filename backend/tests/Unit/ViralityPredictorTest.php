<?php

namespace Tests\Unit;

use App\Models\AccountProfile;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\Trend;
use App\Models\User;
use App\Services\GrowthAgent\ViralityPredictorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViralityPredictorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_loud_live_moment_with_a_specific_take_is_worth_posting(): void
    {
        $user = $this->user();
        $trend = new Trend([
            'name' => 'United have scored',
            'score' => 88,
            'metadata' => [
                'text' => 'United have scored and the ground has gone',
                'author' => 'FabrizioRomano',
                'author_followers' => 2_000_000,
                'minutes_ago' => 8,
            ],
        ]);

        $prediction = app(ViralityPredictorService::class)->predict(
            $user,
            'That finish was filthy. Keep the ball.',
            $trend,
            $user->accountProfile,
            null,
            60,
        );

        $this->assertTrue($prediction['worth_posting']);
        $this->assertGreaterThanOrEqual(70, $prediction['score']);
        $this->assertGreaterThanOrEqual(90, $prediction['parts']['moment']);
        $this->assertContains($prediction['estimated_reach'], ['medium', 'high']);
    }

    public function test_a_bio_echo_on_a_quiet_room_is_held(): void
    {
        $user = $this->user();

        $prediction = app(ViralityPredictorService::class)->predict(
            $user,
            'Cheap weeknight meals for one, with honest shortcuts.',
            null,
            $user->accountProfile,
            null,
            75,
            35,
        );

        $this->assertFalse($prediction['worth_posting']);
        $this->assertLessThan(60, $prediction['score']);
        $this->assertLessThan(45, $prediction['parts']['draft']);
        $this->assertStringContainsString('Held', $prediction['reason']);
    }

    public function test_dead_posts_on_this_account_pull_the_history_score_down(): void
    {
        $user = $this->user();
        $account = ConnectedAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => '42',
            'username' => 'mackie',
            'access_token' => 'token',
            'is_active' => true,
        ]);

        foreach (['One', 'Two', 'Three'] as $i => $label) {
            Post::create([
                'user_id' => $user->id,
                'connected_account_id' => $account->id,
                'content' => $label,
                'content_type' => 'tweet',
                'status' => 'posted',
                'impressions' => 400,
                'replies' => 0,
                'likes' => 0,
                'posted_at' => now()->subHours(8 + $i),
            ]);
        }

        $history = app(ViralityPredictorService::class)->historyScore($user, $account->id);

        $this->assertSame(28.0, $history);
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
}
