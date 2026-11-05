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

    public function test_a_batch_posts_the_strongest_take_and_leaves_the_rest(): void
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

        $takes = [
            'That finish was filthy. Keep the ball.',
            'Thoughts on the game today?',
            'As a United fan I think we need to be better.',
        ];

        $items = [];
        foreach ($takes as $content) {
            $items[] = array_merge(
                app(ViralityPredictorService::class)->predict(
                    $user,
                    $content,
                    $trend,
                    $user->accountProfile,
                    null,
                    60,
                ),
                ['content' => $content],
            );
        }

        $ranked = app(ViralityPredictorService::class)->pickOne($items, 60);

        $this->assertCount(3, $ranked);
        $this->assertSame('post', $ranked[0]['pick']);
        $this->assertTrue($ranked[0]['worth_posting']);
        $this->assertStringContainsString('filthy', $ranked[0]['content']);
        $this->assertSame('leave', $ranked[1]['pick']);
        $this->assertSame('leave', $ranked[2]['pick']);
        $this->assertFalse($ranked[1]['worth_posting']);
        $this->assertFalse($ranked[2]['worth_posting']);

        $scores = array_map(fn (array $item) => (int) $item['score'], $ranked);
        $this->assertCount(3, array_unique($scores));
        $this->assertGreaterThan($scores[1], $scores[0]);
        $this->assertGreaterThan($scores[2], $scores[0]);
    }

    public function test_a_quiet_batch_considers_one_take_and_still_holds(): void
    {
        $user = $this->user();
        $takes = [
            'Rice, eggs, and soy sauce. Dinner for 40p.',
            'Ready meals cost 3x more. Here is the maths.',
            'What is the one ingredient you always run out of?',
        ];

        $items = [];
        foreach ($takes as $content) {
            $items[] = array_merge(
                app(ViralityPredictorService::class)->predict(
                    $user,
                    $content,
                    null,
                    $user->accountProfile,
                    null,
                    75,
                    35,
                ),
                ['content' => $content],
            );
        }

        $ranked = app(ViralityPredictorService::class)->pickOne($items, 75);

        $this->assertSame('hold', $ranked[0]['pick']);
        $this->assertFalse($ranked[0]['worth_posting']);
        $this->assertSame('leave', $ranked[1]['pick']);
        $this->assertSame('leave', $ranked[2]['pick']);
        $this->assertCount(3, array_unique(array_map(fn (array $item) => (int) $item['score'], $ranked)));
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
