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
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
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

    public function test_the_same_niche_moment_does_not_post_again_a_few_minutes_later(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '881']], 201),
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
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

    public function test_a_quiet_niche_posts_once_three_hours_have_passed(): void
    {
        $this->useRulesEngine();
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '882']], 201),
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
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

        $recent = $this->user(level: 3);
        $this->connectX($recent);
        $recent->posts()->create([
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

        $this->assertSame('WAIT', app(GrowthAgentService::class)->evaluateOpportunities($recent->id));

        $quiet = $this->user(level: 3, email: 'quiet-three@example.com');
        $this->connectX($quiet, '77');
        $quiet->posts()->create([
            'content' => 'Posted three hours ago.',
            'content_type' => 'tweet',
            'status' => 'posted',
            'posted_at' => now()->subHours(3)->subMinute(),
        ]);

        $decision = app(GrowthAgentService::class)->evaluateOpportunities($quiet->id);

        $this->assertSame('POST', $decision);
        $this->assertSame('posted', Post::where('user_id', $quiet->id)->where('content', 'Three hours of quiet, and the niche still has a side to take.')->value('status'));
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
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
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
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
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

            return str_contains($prompt, 'The conversation to join: Weeknight rice dinners')
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
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
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

    private function user(int $level, bool $autoPost = true, string $email = 'cook@example.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'x',
            'goal' => 'grow_followers',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'mode' => 'reactive',
            'automation_level' => $level,
            'auto_post_enabled' => $autoPost,
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
}
