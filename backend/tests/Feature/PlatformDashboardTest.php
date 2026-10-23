<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_platform_dashboard_shows_only_its_own_work(): void
    {
        config(['openai.api_key' => '']);
        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');

        $api->postJson('/api/account/setup/platforms', ['platforms' => ['x', 'tiktok']])->assertOk();
        $api->postJson('/api/account/setup/about', [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and share the shortcuts that work.',
            'audience' => 'Students and young workers cooking for one',
            'tone' => ['funny'],
        ])->assertOk();
        $api->postJson('/api/account/setup/platforms/x', [
            'goals' => ['grow_followers'],
            'automation_level' => 2,
            'mode' => 'reactive',
        ])->assertOk();
        $api->postJson('/api/account/setup/platforms/tiktok', ['goals' => ['for_you']])->assertOk();

        $user->posts()->create(['content' => 'X draft', 'content_type' => 'tweet', 'status' => 'draft']);
        $user->posts()->create(['content' => 'TikTok draft', 'content_type' => 'tiktok_caption', 'status' => 'draft']);

        $api->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('chosen', true)
            ->assertJsonPath('setup.completed', true)
            ->assertJsonPath('counts.drafts', 1)
            ->assertJsonPath('drafts.0.content', 'TikTok draft')
            ->assertJsonPath('metrics.0.label', 'Views');

        $api->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('counts.drafts', 1)
            ->assertJsonPath('drafts.0.content', 'X draft')
            ->assertJsonPath('setup.settings.automation_level', 2);

        $api->getJson('/api/dashboard/platform/youtube')
            ->assertOk()
            ->assertJsonPath('chosen', false)
            ->assertJsonPath('counts.drafts', 0)
            ->assertJsonPath('metrics.0.label', 'Subscribers');
    }

    public function test_moments_come_from_the_model_and_are_not_the_same_catalog(): void
    {
        Cache::flush();
        config(['openai.api_key' => 'test-key']);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['moments' => [
                        ['name' => 'The 40p dinner I keep repeating', 'suggestion' => 'Rice, an egg, and the one thing you leave out.'],
                        ['name' => 'I stopped buying the meal deal', 'suggestion' => 'A short story about the week the maths won.'],
                        ['name' => 'What do you always run out of?', 'suggestion' => 'A question people will answer from their own kitchen.'],
                        ['name' => 'Ready meals are not the cheap option', 'suggestion' => 'An opinion with the price of one bag of rice.'],
                    ]])],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/account/setup/platforms', ['platforms' => ['x']])->assertOk();
        $api->postJson('/api/account/setup/about', [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and share the shortcuts that work.',
            'audience' => 'Students cooking for one',
            'tone' => ['funny'],
        ])->assertOk();
        $api->postJson('/api/account/setup/platforms/x', [
            'goals' => ['grow_followers'],
            'automation_level' => 2,
            'mode' => 'reactive',
        ])->assertOk();

        $api->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('ideas.0.name', 'The 40p dinner I keep repeating')
            ->assertJsonPath('ideas.0.suggestion', 'Rice, an egg, and the one thing you leave out.')
            ->assertJsonCount(4, 'ideas');

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $prompt = $parameters['messages'][1]['content'];

            return str_contains($prompt, 'Budget home cooking')
                && str_contains($prompt, 'Do not repeat these earlier moments')
                && str_contains($prompt, 'Invent exactly 4 fresh moments');
        });
    }
}
