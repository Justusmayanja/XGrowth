<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use OpenAI\Laravel\Facades\OpenAI;
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

    public function test_opening_the_desk_does_not_spend_an_ai_call(): void
    {
        Cache::flush();
        config(['openai.api_key' => 'test-key']);
        PlatformSetting::query()->where('key', 'web_search_enabled')->update(['value' => '0']);
        app(PlatformSettingsService::class)->clearCache();
        OpenAI::fake();

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
            ->assertJsonPath('ideas', []);

        OpenAI::assertNothingSent();
    }
}
