<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\PlatformSetting;
use App\Models\Post;
use App\Models\RateLimitBudget;
use App\Models\Trend;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\TrendEngine\OpportunityScorerService;
use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class ComposeSuggestTest extends TestCase
{
    use RefreshDatabase;

    private function userWithProfile(): User
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'side_hustle',
            'platforms' => 'x',
            'goal' => 'monetize,grow_followers',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'onboarding_completed' => true,
        ]);

        return $user;
    }

    public function test_describe_mode_still_needs_a_description(): void
    {
        $this->actingAs($this->userWithProfile(), 'sanctum')
            ->postJson('/api/content/generate', ['mode' => 'describe', 'content_type' => 'tweet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('topic');
    }

    public function test_suggest_mode_drafts_from_the_account_without_a_topic(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->disableWebSearch();

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [
                        ['content' => 'Rice, eggs, and soy sauce. Dinner for 40p.', 'angle' => 'Cheapest meal of the week', 'virality_score' => 80, 'estimated_reach' => 'high'],
                        ['content' => 'Ready meals cost 3x more. Here is the maths.', 'angle' => 'Ready meals vs cooking', 'virality_score' => 74, 'estimated_reach' => 'medium'],
                        ['content' => 'What is the one ingredient you always run out of?', 'angle' => 'Reply question', 'virality_score' => 70, 'estimated_reach' => 'medium'],
                    ]])],
                ]],
            ]),
        ]);

        $user = $this->userWithProfile();
        Post::create(['user_id' => $user->id, 'content' => 'Pasta for one, under a pound.', 'content_type' => 'tweet', 'status' => 'draft']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/generate', ['mode' => 'suggest', 'content_type' => 'tweet'])
            ->assertOk()
            ->assertJsonCount(3, 'variants')
            ->assertJsonPath('variants.0.angle', 'Cheapest meal of the week');

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $prompt = $parameters['messages'][1]['content'];

            return str_contains($prompt, 'No topic was given')
                && str_contains($prompt, 'Budget home cooking')
                && str_contains($prompt, 'work toward X creator payouts')
                && str_contains($prompt, 'Pasta for one, under a pound.')
                && str_contains($prompt, 'Never quote them')
                && str_contains($prompt, "Write from this account's side")
                && ! str_contains($prompt, 'Never bend the niche');
        });
    }

    public function test_a_draft_that_copies_the_setup_is_rewritten(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->disableWebSearch();

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Cheap weeknight meals for one, with honest shortcuts. Rice again.',
                        'angle' => 'Copied the bio',
                        'virality_score' => 40,
                        'estimated_reach' => 'low',
                    ]]])],
                ]],
            ]),
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'City and Arsenal are flying, and we already had this standard in May.',
                        'angle' => 'Answer the form table',
                        'virality_score' => 77,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $this->actingAs($this->userWithProfile(), 'sanctum')
            ->postJson('/api/content/generate', [
                'mode' => 'describe',
                'topic' => 'Arsenal and City are both in form',
                'content_type' => 'tweet',
            ])
            ->assertOk()
            ->assertJsonPath('variants.0.content', 'City and Arsenal are flying, and we already had this standard in May.');
    }

    public function test_live_conversations_are_joined_even_when_they_are_not_the_niche(): void
    {
        $user = $this->userWithProfile();
        Trend::create([
            'name' => 'Transfer deadline drama',
            'slug' => 'transfer-deadline-drama',
            'score' => 99,
            'velocity' => 10,
            'growth_rate' => 30,
            'competition' => 'high',
            'niche_tags' => ['football'],
            'detected_at' => now(),
            'metadata' => ['source' => 'mock'],
        ]);

        app(TrendEngineService::class)->rememberLiveConversations([
            ['name' => 'Arsenal and City both in form', 'detail' => 'Both won again this week.'],
        ]);

        $ranked = app(OpportunityScorerService::class)->rankedForProfile($user->accountProfile, 5);

        $this->assertSame('Arsenal and City both in form', $ranked[0]['trend']->name);
        $this->assertStringContainsString("this account's side", $ranked[0]['suggestion']);
        $this->assertSame(0, Trend::query()->where('metadata->source', 'mock')->count());
    }

    public function test_a_full_daily_draft_count_still_writes_a_tweet(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->disableWebSearch();

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Rice, eggs, and soy sauce. Dinner for 40p.',
                        'angle' => 'Cheapest meal of the week',
                        'virality_score' => 80,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = $this->userWithProfile();
        RateLimitBudget::create([
            'user_id' => $user->id,
            'action_type' => 'ai_generation',
            'count' => 30,
            'daily_limit' => 30,
            'budget_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/generate', [
                'mode' => 'describe',
                'topic' => 'A cheap dinner',
                'content_type' => 'tweet',
            ])
            ->assertOk()
            ->assertJsonPath('variants.0.content', 'Rice, eggs, and soy sauce. Dinner for 40p.');
    }

    private function disableWebSearch(): void
    {
        PlatformSetting::query()->where('key', 'web_search_enabled')->update(['value' => '0']);
        app(PlatformSettingsService::class)->clearCache();
    }
}
