<?php

namespace Tests\Feature;

use App\Models\PlatformSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSetupTest extends TestCase
{
    use RefreshDatabase;

    private function about(): array
    {
        return [
            'purpose' => 'side_hustle',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and want to turn it into income.',
            'audience' => 'Students and young workers cooking for one',
            'tone' => ['funny'],
        ];
    }

    public function test_platform_chapters_save_separately_and_finish_onboarding_after_the_first(): void
    {
        config(['openai.api_key' => '']);
        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');

        $api->postJson('/api/account/setup/platforms', ['platforms' => ['youtube', 'x']])
            ->assertOk()
            ->assertJsonPath('platforms', ['x', 'youtube']);

        $api->postJson('/api/account/setup/platforms/x', [
            'goals' => ['monetize'],
            'automation_level' => 1,
            'mode' => 'reactive',
        ])->assertStatus(422)->assertJsonValidationErrors('about');

        $api->postJson('/api/account/setup/about', $this->about())
            ->assertOk()
            ->assertJsonPath('about_complete', true)
            ->assertJsonPath('onboarding_completed', false);

        $api->postJson('/api/account/setup/platforms/x', [
            'goals' => ['monetize', 'grow_followers'],
            'automation_level' => 2,
            'mode' => 'proactive',
        ])->assertOk()
            ->assertJsonPath('onboarding_completed', true)
            ->assertJsonPath('setups.x.completed', true)
            ->assertJsonPath('setups.youtube.completed', false);

        $profile = $user->fresh()->accountProfile;
        $this->assertSame('monetize,grow_followers', $profile->goal);
        $this->assertSame(2, $profile->automation_level);
        $this->assertSame('proactive', $profile->mode);

        $api->postJson('/api/account/setup/platforms/youtube', [
            'goals' => ['for_you'],
            'publish' => 'wait',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ])->assertStatus(422)->assertJsonValidationErrors('goals.0');

        $api->postJson('/api/account/setup/platforms/youtube', [
            'goals' => ['subscribers', 'monetize'],
            'publish' => 'wait',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ])->assertOk()
            ->assertJsonPath('setups.youtube.settings.publish', 'wait')
            ->assertJsonPath('setups.youtube.settings.video_mode', 'short')
            ->assertJsonPath('setups.youtube.settings.format', 'text_on_screen');

        $this->assertSame('monetize,grow_followers', $profile->fresh()->goal);
        $this->assertSame(
            'gain subscribers, work toward the YouTube Partner Program',
            $profile->fresh()->goalSentenceFor('youtube'),
        );
        $this->assertSame(2, PlatformSetup::where('user_id', $user->id)->count());
    }

    public function test_niche_ideas_fall_back_when_ai_is_not_configured(): void
    {
        config(['openai.api_key' => '']);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/account/setup/niche-ideas', [
                'interests' => 'cooking, football',
                'weekly_time' => 'under_3',
                'presence' => 'faceless',
                'platforms' => ['tiktok'],
            ])
            ->assertOk()
            ->assertJsonPath('source', 'basic')
            ->assertJsonCount(3, 'ideas')
            ->assertJsonPath('ideas.0.niche', 'Cooking for beginners');
    }

    public function test_about_requires_a_niche(): void
    {
        $user = User::factory()->create();
        $payload = $this->about();
        unset($payload['custom_niche']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/account/setup/about', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('niche');
    }
}
