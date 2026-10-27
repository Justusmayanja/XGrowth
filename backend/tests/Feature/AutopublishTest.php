<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\ConnectedAccount;
use App\Models\PlatformSetting;
use App\Models\PlatformSetup;
use App\Models\Post;
use App\Models\Trend;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\GrowthAgent\GrowthAgentService;
use App\Services\GrowthAgent\GrowthBrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class AutopublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openai.api_key' => '']);
    }

    public function test_a_saved_draft_does_not_post_when_the_niche_is_quiet(): void
    {
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '880']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response(['data' => []]),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);
        $draft = $this->draft($user, 'Rice, an egg, and soy sauce. Dinner for 40p.', 82);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('WAIT', $decision);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_a_worldwide_story_is_not_posted_on_a_niche_account(): void
    {
        $this->useRulesEngine();
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '899']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'Trump and Iran are at war tonight',
                'worldnews',
                5_000_000,
                5,
                'tweet-war',
            )),
            'https://api.twitter.com/2/trends/by/woeid/1*' => Http::response([
                'data' => [
                    ['trend_name' => 'Trump and Iran', 'tweet_count' => 90000],
                ],
            ]),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response([
                'data' => [
                    ['trend_name' => 'Trump and Iran', 'tweet_count' => 90000],
                ],
            ]),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $decision);
        $this->assertSame(0, $user->posts()->count());
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_the_same_niche_moment_does_not_post_again_a_few_minutes_later(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '881']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'United have scored and the ground has gone',
                'FabrizioRomano',
                2_000_000,
                8,
                'tweet-1',
            )),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Full time, and the table already says it.',
                        'angle' => 'The result',
                        'virality_score' => 88,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);
        Trend::create([
            'name' => 'The final whistle',
            'slug' => 'final-whistle',
            'score' => 94,
            'velocity' => 20,
            'growth_rate' => 40,
            'competition' => 'low',
            'niche_tags' => ['cooking'],
            'detected_at' => now(),
            'metadata' => ['source' => 'live'],
        ]);

        $this->assertSame('POST', app(GrowthAgentService::class)->evaluateOpportunities($user->id));

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $decision);
        $this->assertSame(1, Post::where('user_id', $user->id)->where('status', 'posted')->count());
        $tweets = Http::recorded()->filter(
            fn (array $pair) => $pair[0]->url() === 'https://api.twitter.com/2/tweets'
        );
        $this->assertCount(1, $tweets);
    }

    public function test_a_quiet_niche_does_not_post_just_because_hours_passed(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '882']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response(['data' => []]),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Three hours of quiet, and the niche still has a side to take.',
                        'angle' => 'After the lull',
                        'virality_score' => 81,
                        'estimated_reach' => 'medium',
                    ]]])],
                ]],
            ]),
        ]);

        $quiet = $this->user(level: 3);
        $this->connectX($quiet);
        $quiet->posts()->create([
            'content' => 'Posted two hours ago.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subHours(2),
        ]);
        Trend::create([
            'name' => 'A mild aside',
            'slug' => 'mild-aside',
            'score' => 10,
            'velocity' => 1,
            'growth_rate' => 0,
            'competition' => 'high',
            'niche_tags' => ['markets'],
            'detected_at' => now(),
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($quiet->id);

        $this->assertSame('WAIT', $decision);
        $this->assertSame(1, Post::where('user_id', $quiet->id)->where('status', 'posted')->count());
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_a_fresh_moment_can_post_minutes_after_the_last_one(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '883']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'United have scored and the ground has gone',
                'FabrizioRomano',
                2_000_000,
                8,
                'tweet-1',
            )),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'That finish was filthy. Keep the ball and stop celebrating like it is a friendly.',
                        'angle' => 'The finish',
                        'virality_score' => 90,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);
        $user->posts()->create([
            'content' => 'Posted a few minutes ago about the lineup.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subMinutes(12),
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('POST', $decision);
        $this->assertSame(
            'posted',
            Post::where('user_id', $user->id)->where('content', 'That finish was filthy. Keep the ball and stop celebrating like it is a friendly.')->value('status')
        );
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $prompt = $parameters['messages'][1]['content'];

            return str_contains($prompt, 'United have scored and the ground has gone')
                && str_contains($prompt, '@FabrizioRomano')
                && str_contains($prompt, 'Do not copy the sentence')
                && str_contains($prompt, 'Do not add a job title');
        });

        $again = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $again);
        $this->assertSame(2, Post::where('user_id', $user->id)->where('status', 'posted')->count());
    }

    public function test_a_new_moment_still_waits_a_few_minutes_after_a_post(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '884']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'United have scored again',
                'FabrizioRomano',
                2_000_000,
                2,
            )),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);
        $user->posts()->create([
            'content' => 'Just posted about the first goal.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subMinutes(3),
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $decision);
        $this->assertSame(1, Post::where('user_id', $user->id)->where('status', 'posted')->count());
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_a_stale_claim_is_not_treated_as_news(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '885']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'Ruben Amorim is still the Manchester United coach',
                'oldtake',
                5000,
                60 * 24 * 3,
            )),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake();

        $user = $this->user(level: 3);
        $this->connectX($user);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $decision);
        $this->assertNull(Post::where('user_id', $user->id)->value('id'));
        OpenAI::assertNothingSent();
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_a_live_moment_is_checked_again_within_minutes(): void
    {
        $user = $this->user(level: 3);
        $brain = app(GrowthBrainService::class);
        Cache::put('growth_brain:last_eval:'.$user->id, now()->subMinutes(10)->timestamp, now()->addDay());
        $brain->noteHeat($user->id, false);
        $this->assertTrue($brain->shouldSkipScheduledEvaluation($user->id));

        $brain->noteHeat($user->id, true);
        $this->assertFalse($brain->shouldSkipScheduledEvaluation($user->id));

        Cache::put('growth_brain:last_eval:'.$user->id, now()->timestamp, now()->addDay());
        $this->assertTrue($brain->shouldSkipScheduledEvaluation($user->id));
    }

    public function test_full_automation_holds_a_draft_that_is_not_viral_enough(): void
    {
        Http::fake();
        $user = $this->user(level: 3);
        $this->connectX($user);
        $draft = $this->draft($user, 'A vague post about food.', 40);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNotSame('posted', $decision);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_controlled_accounts_do_not_publish(): void
    {
        Http::fake();
        $user = $this->user(level: 1, autoPost: false);
        $this->connectX($user);
        $draft = $this->draft($user, 'A strong cooking take.', 95);

        app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('draft', $draft->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_level_two_does_not_publish_without_a_hot_niche_moment(): void
    {
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '77']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response(['data' => []]),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);

        $quiet = $this->user(level: 2);
        $this->connectX($quiet);
        $held = $this->draft($quiet, 'Fine, but not strong.', 70);
        app(GrowthAgentService::class)->evaluateOpportunities($quiet->id);
        $this->assertSame('draft', $held->fresh()->status);

        $loud = $this->user(level: 2, email: 'loud@example.com');
        $this->connectX($loud, '99');
        $posted = $this->draft($loud, 'The meal deal is the expensive option.', 86);
        app(GrowthAgentService::class)->evaluateOpportunities($loud->id);

        $this->assertSame('draft', $posted->fresh()->status);
        $this->assertSame('draft', $held->fresh()->status);
    }

    public function test_full_automation_drafts_and_posts_when_the_niche_moment_is_viral(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '501']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'Weeknight rice dinners are the United conversation tonight',
                'utdkitchen',
                2_000_000,
                8,
                'tweet-rice',
            )),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Ready meals cost three times more than rice and an egg.',
                        'angle' => 'Price of the meal deal',
                        'virality_score' => 84,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);
        Trend::create([
            'name' => 'Weeknight rice dinners',
            'slug' => 'weeknight-rice',
            'score' => 90,
            'velocity' => 12,
            'growth_rate' => 40,
            'competition' => 'low',
            'niche_tags' => ['cooking'],
            'detected_at' => now(),
            'metadata' => ['source' => 'live'],
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $post = Post::where('user_id', $user->id)->first();
        $this->assertSame('POST', $decision);
        $this->assertNotNull($post);
        $this->assertSame('posted', $post->status);
        $this->assertSame('501', $post->platform_post_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $prompt = $parameters['messages'][1]['content'];

            return str_contains($prompt, 'The conversation to join: Weeknight rice dinners are the United conversation tonight')
                && str_contains($prompt, 'Never quote them')
                && str_contains($prompt, 'the account lends money')
                && ! str_contains($prompt, 'Weeknight rice dinners —');
        });
    }

    public function test_a_quiet_niche_does_not_post(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '502']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response(['data' => []]),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'The cheapest dinner I cook is rice, an egg, and soy sauce.',
                        'angle' => 'Cheapest dinner',
                        'virality_score' => 81,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);
        Trend::create([
            'name' => 'Unrelated market note',
            'slug' => 'unrelated-market',
            'score' => 10,
            'velocity' => 1,
            'growth_rate' => 0,
            'competition' => 'high',
            'niche_tags' => ['markets'],
            'detected_at' => now(),
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $decision);
        $this->assertNull(Post::where('user_id', $user->id)->value('status'));
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_saving_full_automation_updates_the_x_setup(): void
    {
        $user = $this->user(level: 1, autoPost: false);
        PlatformSetup::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'goals' => 'grow_followers',
            'settings' => ['automation_level' => 1, 'mode' => 'reactive'],
            'completed_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/account/automation', [
                'automation_level' => 3,
                'auto_post_enabled' => true,
                'mode' => 'proactive',
                'engine_paused' => false,
            ])
            ->assertOk()
            ->assertJsonPath('automation.automation_level', 3);

        $setup = PlatformSetup::where('user_id', $user->id)->where('platform', 'x')->first();
        $this->assertSame(3, $setup->settings['automation_level']);
        $this->assertSame('proactive', $setup->settings['mode']);
        $this->assertTrue($setup->settings['auto_post_enabled']);
    }

    public function test_the_x_desk_and_the_next_check_follow_the_saved_rules(): void
    {
        $user = $this->user(level: 1, autoPost: false);
        PlatformSetup::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'goals' => 'grow_followers',
            'settings' => ['automation_level' => 1, 'mode' => 'reactive'],
            'completed_at' => now(),
        ]);
        Cache::put('growth_brain:last_eval:'.$user->id, now()->timestamp, now()->addDay());
        $this->assertTrue(app(GrowthBrainService::class)->shouldSkipScheduledEvaluation($user->id));

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/account/automation', [
                'automation_level' => 3,
                'auto_post_enabled' => true,
                'mode' => 'proactive',
                'engine_paused' => false,
            ])
            ->assertOk();

        $this->assertFalse(app(GrowthBrainService::class)->shouldSkipScheduledEvaluation($user->id));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('setup.settings.automation_level', 3)
            ->assertJsonPath('setup.settings.mode', 'proactive')
            ->assertJsonPath('setup.settings.auto_post_enabled', true)
            ->assertJsonPath('setup.settings.engine_paused', false);
    }

    private function useRulesEngine(): void
    {
        PlatformSetting::query()->where('key', 'growth_brain_enabled')->update(['value' => '0']);
        PlatformSetting::query()->where('key', 'web_search_enabled')->update(['value' => '0']);
        app(PlatformSettingsService::class)->clearCache();
    }

    public function test_a_big_account_is_sometimes_left_alone(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        $user = $this->user(level: 3);
        $this->connectX($user);
        $user->posts()->create([
            'content' => 'Posted a few minutes ago about the lineup.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subMinutes(12),
        ]);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '890']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'United have scored and the ground has gone',
                'FabrizioRomano',
                2_000_000,
                8,
                $this->tweetIdWhere($user->id, 'skip'),
            )),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $decision);
        $this->assertSame(1, Post::where('user_id', $user->id)->where('status', 'posted')->count());
        $this->assertStringContainsString('Not every big account', (string) AiDecision::where('user_id', $user->id)->latest('id')->value('reason'));
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_two_hours_of_quiet_still_waits_and_five_hours_gets_one_view(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '891']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response(['data' => []]),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Been away from the timeline. That price still looks wrong.',
                        'angle' => 'After a stretch',
                        'virality_score' => 84,
                        'estimated_reach' => 'medium',
                    ]]])],
                ]],
            ]),
        ]);
        Trend::create([
            'name' => 'A mild aside',
            'slug' => 'mild-aside-ceiling',
            'score' => 40,
            'velocity' => 1,
            'growth_rate' => 0,
            'competition' => 'high',
            'niche_tags' => ['cooking'],
            'detected_at' => now(),
            'metadata' => ['source' => 'live'],
        ]);

        $recent = $this->user(level: 3);
        $this->connectX($recent);
        $recent->posts()->create([
            'content' => 'Posted two hours ago.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subHours(2),
        ]);
        $this->assertSame('WAIT', app(GrowthAgentService::class)->evaluateOpportunities($recent->id));

        $quiet = $this->user(level: 3, email: 'quiet-long@example.com');
        $this->connectX($quiet, '77');
        $quiet->posts()->create([
            'content' => 'Posted five hours ago.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subHours(5),
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($quiet->id);

        $this->assertSame('POST', $decision);
        $this->assertSame(2, Post::where('user_id', $quiet->id)->where('status', 'posted')->where('content_type', 'tweet')->count());
    }

    public function test_a_like_goes_to_one_post_and_not_the_next(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        $user = $this->user(level: 3);
        $user->accountProfile->update(['auto_engage_enabled' => true, 'auto_reply_enabled' => false]);
        $this->connectX($user);
        $user->posts()->create([
            'content' => 'Just posted.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subMinutes(3),
        ]);
        $liked = $this->tweetIdWhere($user->id, 'like');
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '892']], 201),
            'https://api.twitter.com/2/users/*/likes' => Http::response(['data' => ['liked' => true]]),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response([
                'data' => [
                    $this->nicheTweet('United have scored again tonight', 'FabrizioRomano', 2_000_000, 10, $liked)['data'][0],
                    $this->nicheTweet('Another angle on the same match', 'someone', 400, 12, 'tweet-other')['data'][0],
                ],
                'includes' => [
                    'users' => [[
                        'id' => 'author-1',
                        'username' => 'FabrizioRomano',
                        'public_metrics' => ['followers_count' => 2_000_000],
                    ]],
                ],
            ]),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);

        app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $likes = Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), '/likes'));
        $this->assertCount(1, $likes);
        $this->assertSame($liked, $likes->first()[0]->data()['tweet_id']);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
    }

    public function test_a_reply_is_sent_to_one_post_when_replies_are_on(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        $user = $this->user(level: 3);
        $user->accountProfile->update(['auto_reply_enabled' => true, 'auto_engage_enabled' => false]);
        $this->connectX($user);
        $user->posts()->create([
            'content' => 'Just posted.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subMinutes(3),
        ]);
        $replyTo = $this->tweetIdWhere($user->id, 'reply');
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '893']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'The United price just jumped and nobody is calm',
                'marketsguy',
                800,
                15,
                $replyTo,
            )),
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/*' => Http::response(['data' => []], 404),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['drafts' => [[
                        'content' => 'That jump is the part people are going to feel tomorrow.',
                        'tone' => 'funny',
                    ]]])],
                ]],
            ]),
        ]);

        app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        Http::assertSent(function ($request) use ($replyTo) {
            return $request->url() === 'https://api.twitter.com/2/tweets'
                && ($request->data()['reply']['in_reply_to_tweet_id'] ?? null) === $replyTo
                && str_contains((string) $request->data()['text'], 'That jump');
        });
        $this->assertSame(1, Post::where('user_id', $user->id)->where('content_type', 'reply')->count());
    }

    public function test_a_related_post_is_reposted_and_another_is_quoted(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        $user = $this->user(level: 3);
        $this->connectX($user);
        $user->posts()->create([
            'content' => 'Posted two hours ago.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subHours(2),
        ]);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'quote-1']], 201),
            'https://api.twitter.com/2/users/*/retweets' => Http::response(['data' => ['retweeted' => true]]),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response([
                'data' => [
                    $this->loudTweet('tweet-repost', 'United looked flat in that second half', 90, 90),
                    $this->loudTweet('tweet-quote', 'United still have a game on Thursday', 70, 80),
                ],
                'includes' => [
                    'users' => [[
                        'id' => 'author-1',
                        'username' => 'beatwriter',
                        'public_metrics' => ['followers_count' => 800],
                    ]],
                ],
            ]),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['drafts' => [[
                        'content' => 'Thursday is the one that actually matters. https://example.com',
                        'tone' => 'funny',
                    ]]])],
                ]],
            ]),
        ]);

        $engine = app(GrowthAgentService::class);
        $engine->evaluateOpportunities($user->id);

        $reposts = $this->recordedRetweets();
        $quotes = $this->recordedQuotes();
        $this->assertCount(1, $reposts);
        $this->assertSame('tweet-repost', $reposts->first()[0]->data()['tweet_id']);
        $this->assertCount(1, $quotes);
        $this->assertSame('tweet-quote', $quotes->first()[0]->data()['quote_tweet_id']);
        $this->assertStringNotContainsString('http', (string) $quotes->first()[0]->data()['text']);
        $this->assertSame(1, Post::where('user_id', $user->id)->where('content_type', 'quote')->count());

        $engine->evaluateOpportunities($user->id);
        $this->assertCount(1, $this->recordedRetweets());
        $this->assertCount(1, $this->recordedQuotes());
    }

    public function test_a_full_day_does_not_repost_or_quote(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        $user = $this->user(level: 3, email: 'capped@example.com');
        $this->connectX($user);
        Cache::put(app(ApiSpendService::class)->dailySpendKey($user->id), ApiSpendService::DAILY_CAP_MILLS, now()->addHour());
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'quote-2']], 201),
            'https://api.twitter.com/2/users/*/retweets' => Http::response(['data' => ['retweeted' => true]]),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response([
                'data' => [
                    $this->loudTweet('tweet-repost', 'United looked flat in that second half', 90, 90),
                ],
            ]),
        ]);
        OpenAI::fake();

        app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        Http::assertNothingSent();
        OpenAI::assertNothingSent();
    }

    private function user(int $level, bool $autoPost = true, string $email = 'cook@example.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'x',
            'goal' => 'grow_followers',
            'custom_niche' => 'United',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'mode' => 'reactive',
            'automation_level' => $level,
            'auto_post_enabled' => $autoPost,
            'auto_reply_enabled' => false,
            'auto_engage_enabled' => false,
            'engine_paused' => false,
            'onboarding_completed' => true,
            'max_silence_minutes' => 60,
        ]);

        return $user;
    }

    private function connectX(User $user, string $accountId = '42'): void
    {
        ConnectedAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => $accountId,
            'username' => 'mackie',
            'access_token' => 'user-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
    }

    private function draft(User $user, string $content, int $virality): Post
    {
        return $user->posts()->create([
            'content' => $content,
            'content_type' => 'tweet',
            'status' => 'draft',
            'virality_predicted' => $virality,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function loudTweet(string $id, string $text, int $likes, int $minutesAgo): array
    {
        return [
            'id' => $id,
            'text' => $text,
            'author_id' => 'author-1',
            'created_at' => now()->subMinutes($minutesAgo)->utc()->toIso8601String(),
            'public_metrics' => [
                'like_count' => $likes,
                'reply_count' => 10,
                'impression_count' => 1000,
            ],
        ];
    }

    private function recordedRetweets(): \Illuminate\Support\Collection
    {
        return Http::recorded()->filter(
            fn (array $pair) => str_contains($pair[0]->url(), '/retweets')
        );
    }

    private function recordedQuotes(): \Illuminate\Support\Collection
    {
        return Http::recorded()->filter(
            fn (array $pair) => $pair[0]->url() === 'https://api.twitter.com/2/tweets'
                && isset($pair[0]->data()['quote_tweet_id'])
        );
    }

    private function nicheTweet(string $text, string $username, int $followers, int $minutesAgo, ?string $id = null): array
    {
        return [
            'data' => [[
                'id' => $id ?? 'tweet-'.$minutesAgo,
                'text' => $text,
                'author_id' => 'author-1',
                'created_at' => now()->subMinutes($minutesAgo)->utc()->toIso8601String(),
                'public_metrics' => [
                    'like_count' => 12,
                    'reply_count' => 2,
                    'impression_count' => 400,
                ],
            ]],
            'includes' => [
                'users' => [[
                    'id' => 'author-1',
                    'username' => $username,
                    'public_metrics' => ['followers_count' => $followers],
                ]],
            ],
        ];
    }

    public function test_a_hot_story_is_read_again_only_after_the_watch_window(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '901']], 201),
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'United have scored and the ground has gone',
                'FabrizioRomano',
                2_000_000,
                8,
                'tweet-watch',
            )),
        ]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'That finish was filthy.',
                        'angle' => 'The finish',
                        'virality_score' => 90,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = $this->user(level: 3);
        $this->connectX($user);
        $user->connectedAccounts()->update([
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(3),
        ]);
        $engine = app(GrowthAgentService::class);
        $engine->evaluateOpportunities($user->id);

        $this->travel(2)->hours();
        $engine->evaluateOpportunities($user->id);

        $pulls = fn () => collect(Http::recorded())->filter(
            fn (array $pair) => str_contains($pair[0]->url(), '/timelines/reverse_chronological')
        );
        $this->assertCount(1, $pulls());

        $this->travel(7)->hours();
        $engine->evaluateOpportunities($user->id);
        $this->assertCount(2, $pulls());
    }

    public function test_a_quiet_story_does_not_ask_the_model(): void
    {
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/users/*/timelines/reverse_chronological*' => Http::response($this->nicheTweet(
                'Rice is still the cheapest dinner',
                'home_cook',
                200,
                60,
                'tweet-quiet',
            )),
        ]);
        OpenAI::fake();

        $user = $this->user(level: 3);
        $this->connectX($user);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($user->id);

        $this->assertSame('WAIT', $decision);
        OpenAI::assertNothingSent();
    }

    private function tweetIdWhere(int $userId, string $kind): string
    {
        for ($n = 1; $n <= 80; $n++) {
            $id = 'tweet-'.$n;
            $slot = match ($kind) {
                'speak' => hexdec(substr(md5($userId.'|tweet:'.$id), 0, 4)) % 2,
                'skip' => hexdec(substr(md5($userId.'|tweet:'.$id), 0, 4)) % 2,
                'like' => hexdec(substr(md5($userId.'|'.$id.'|like'), 0, 4)) % 4,
                'reply' => hexdec(substr(md5($userId.'|'.$id.'|reply'), 0, 4)) % 5,
                default => 1,
            };
            $wanted = $kind === 'speak' ? 0 : ($kind === 'skip' ? 1 : 0);
            if ($slot === $wanted) {
                return $id;
            }
        }

        $this->fail('No tweet id matched '.$kind);
    }
}
