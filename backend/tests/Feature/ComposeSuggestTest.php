<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\Post;
use App\Models\User;
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
                && str_contains($prompt, 'Pasta for one, under a pound.');
        });
    }
}
