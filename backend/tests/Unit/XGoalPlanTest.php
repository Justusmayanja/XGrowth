<?php

namespace Tests\Unit;

use App\Models\AccountProfile;
use App\Models\Trend;
use App\Models\User;
use App\Services\GrowthAgent\XGoalPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XGoalPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_monetize_puts_the_post_that_already_has_an_audience_first(): void
    {
        $plan = app(XGoalPlan::class);
        $quiet = new Trend([
            'name' => 'A quiet take',
            'metadata' => ['tweet_id' => '1', 'author_followers' => 20],
        ]);
        $loud = new Trend([
            'name' => 'A big account',
            'metadata' => ['tweet_id' => '2', 'author_followers' => 200000],
        ]);

        $ordered = $plan->orderMoments(['monetize'], [
            ['trend' => $quiet, 'opportunity_score' => 85],
            ['trend' => $loud, 'opportunity_score' => 80],
        ]);

        $this->assertSame('A big account', $ordered[0]['trend']->name);
        $this->assertTrue($plan->prefersReply(['monetize']));
        $this->assertTrue($plan->hasAudience($loud));
        $this->assertFalse($plan->prefersReply(['grow_followers']));
    }

    public function test_grow_followers_keeps_the_original_order(): void
    {
        $plan = app(XGoalPlan::class);
        $first = new Trend(['name' => 'First', 'metadata' => ['author_followers' => 10]]);
        $second = new Trend(['name' => 'Second', 'metadata' => ['author_followers' => 500000]]);

        $ordered = $plan->orderMoments(['grow_followers'], [
            ['trend' => $first, 'opportunity_score' => 90],
            ['trend' => $second, 'opportunity_score' => 70],
        ]);

        $this->assertSame('First', $ordered[0]['trend']->name);
        $this->assertEquals(90, $ordered[0]['opportunity_score']);
    }

    public function test_promote_the_business_skips_a_moment_that_is_not_the_offer(): void
    {
        $profile = $this->profile('promote_business', 'We bake custom cakes for offices nearby.');
        $plan = app(XGoalPlan::class);
        $cakes = new Trend([
            'name' => 'Office cake orders',
            'metadata' => ['text' => 'Offices are ordering cakes this week'],
        ]);
        $match = new Trend([
            'name' => 'The match',
            'metadata' => ['text' => 'United looked flat in the second half'],
        ]);

        $this->assertFalse($plan->skipsMoment($profile, $cakes));
        $this->assertTrue($plan->skipsMoment($profile, $match));
    }

    public function test_drive_traffic_keeps_one_saved_link_and_monetize_removes_links(): void
    {
        $plan = app(XGoalPlan::class);
        $traffic = $this->profile('drive_traffic', 'Order at https://bakery.example/cakes');
        $monetize = $this->profile('monetize', 'A fan account for match reactions.');

        $kept = $plan->finishText($traffic, 'Come see the menu https://evil.example/deal');
        $this->assertStringContainsString('https://bakery.example/cakes', $kept);
        $this->assertStringNotContainsString('evil.example', $kept);

        $stripped = $plan->finishText($monetize, 'A take https://evil.example/deal');
        $this->assertStringNotContainsString('http', $stripped);
    }

    public function test_the_lesson_repeats_the_reply_that_earned_impressions(): void
    {
        $lesson = app(XGoalPlan::class)->lesson(['monetize'], [
            ['kind' => 'tweet', 'impressions' => 10, 'replies' => 0, 'content' => 'A post nobody saw'],
            ['kind' => 'reply', 'impressions' => 80, 'replies' => 2, 'content' => 'A reply under the loud post'],
        ]);

        $this->assertStringContainsString('reply', $lesson);
        $this->assertStringContainsString('A reply under the loud post', $lesson);
    }

    public function test_the_desk_number_follows_the_first_goal(): void
    {
        $profile = $this->profile('grow_followers', 'Cheap weeknight meals.');
        $progress = app(XGoalPlan::class)->progress($profile, [
            'followers' => 18,
            'followers_change' => 2,
            'views_known' => true,
            'impressions' => 80,
            'profile_clicks' => 3,
            'replies' => 4,
        ]);

        $this->assertSame('Followers', $progress['label']);
        $this->assertSame(18, $progress['value']);
        $this->assertSame('+2 since yesterday', $progress['sub']);
    }

    private function profile(string $goal, string $description): AccountProfile
    {
        $user = User::factory()->create();

        return AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'x',
            'goal' => $goal,
            'custom_niche' => 'cooking',
            'description' => $description,
            'audience' => 'People nearby',
            'tone' => 'funny',
            'mode' => 'reactive',
            'automation_level' => 2,
            'onboarding_completed' => true,
        ]);
    }
}
