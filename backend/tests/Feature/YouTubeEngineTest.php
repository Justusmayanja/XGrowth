<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\YouTubeSeries;
use App\Models\YouTubeVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class YouTubeEngineTest extends TestCase
{
    use RefreshDatabase;

    private function about(): array
    {
        return [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and share the shortcuts that actually finish.',
            'audience' => 'Students cooking for one',
            'tone' => ['funny'],
        ];
    }

    private function start(array $youtube): User
    {
        config(['openai.api_key' => '']);
        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/account/setup/platforms', ['platforms' => ['youtube']])->assertOk();
        $api->postJson('/api/account/setup/about', $this->about())->assertOk();
        $api->postJson('/api/account/setup/platforms/youtube', $youtube)->assertOk();

        return $user;
    }

    public function test_a_text_short_is_scripted_queued_and_the_next_one_follows_the_result(): void
    {
        $user = $this->start([
            'goals' => ['subscribers', 'search'],
            'publish' => 'auto',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ]);

        $first = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($first);
        $this->assertSame('queued', $first->status);
        $this->assertSame('text_on_screen', $first->format);
        $this->assertGreaterThan(40, strlen((string) $first->script));
        $this->assertFalse($first->package['render']['ready']);
        $this->assertSame('pending', $first->package['render']['provider']);
        $this->assertSame([], $first->package['shots']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/run')
            ->assertOk()
            ->assertJsonCount(1, 'pipeline');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$first->id.'/results', [
                'views' => 1200,
                'average_view_percent' => 22,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'pipeline');

        $first->refresh();
        $this->assertSame('learned', $first->status);
        $this->assertStringContainsString('left early', (string) $first->learning_note);

        $topics = YouTubeVideo::query()->where('user_id', $user->id)->pluck('topic')->unique();
        $this->assertCount(2, $topics);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/youtube')
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'queued')
            ->assertJsonCount(3, 'ideas');
    }

    public function test_waiting_videos_post_only_after_a_yes(): void
    {
        $user = $this->start([
            'goals' => ['watch_time'],
            'publish' => 'wait',
            'video_mode' => 'short',
            'format' => 'faceless_voiceover',
        ]);

        $video = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $this->assertSame('waiting', $video->status);
        $this->assertCount(2, $video->package['shots']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/confirm')
            ->assertOk();

        $this->assertSame('queued', $video->fresh()->status);
    }

    public function test_a_series_scripts_the_season_and_holds_the_next_episode_for_a_week(): void
    {
        $user = $this->start([
            'goals' => ['watch_time', 'subscribers'],
            'publish' => 'auto',
            'video_mode' => 'series',
            'format' => 'faceless_voiceover',
        ]);

        $episode = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $this->assertSame(1, $episode->episode_number);
        $this->assertStringContainsString('EPISODE 1', (string) $episode->script);
        $this->assertSame('narration', $episode->package['render']['kind']);
        $this->assertNotNull(YouTubeSeries::query()->where('user_id', $user->id)->first());

        $this->actingAs($user, 'sanctum')->postJson('/api/youtube/run')->assertOk()->assertJsonCount(1, 'pipeline');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$episode->id.'/results', [
                'views' => 800,
                'average_view_percent' => 61,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'pipeline');

        $this->assertSame(1, YouTubeVideo::query()->where('user_id', $user->id)->count());

        try {
            $this->travel(7)->days();
            $this->actingAs($user, 'sanctum')->postJson('/api/youtube/run')->assertOk();
        } finally {
            $this->travelBack();
        }

        $this->assertSame(2, YouTubeVideo::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, YouTubeVideo::query()->where('user_id', $user->id)->max('episode_number'));
    }

    public function test_uploaded_footage_gets_an_edit_plan_without_a_video_model(): void
    {
        Storage::fake('local');
        $user = $this->start([
            'goals' => ['subscribers'],
            'publish' => 'wait',
            'video_mode' => 'footage',
        ]);

        $this->assertSame(0, YouTubeVideo::query()->where('user_id', $user->id)->count());

        $this->actingAs($user, 'sanctum')
            ->post('/api/youtube/footage', [
                'footage' => UploadedFile::fake()->create('dinner.mp4', 200, 'video/mp4'),
                'note' => 'Start with the onion. Then the cheap cut of chicken. Stop when it simmers.',
            ])
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'awaiting_edit')
            ->assertJsonPath('pipeline.0.package.edit.color.preset', 'clean')
            ->assertJsonPath('pipeline.0.package.edit.silence.trim_over_seconds', 0.6)
            ->assertJsonPath('pipeline.0.package.render.provider', 'pending');

        $video = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $this->assertCount(3, $video->package['edit']['zooms']);
        $this->assertNotEmpty($video->package['edit']['captions']);
        Storage::disk('local')->assertExists($video->source_path);

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/results', [
                'views' => 1,
                'average_view_percent' => 10,
            ])
            ->assertNotFound();
    }

    public function test_footage_upload_is_refused_when_the_channel_is_set_to_shorts(): void
    {
        Storage::fake('local');
        $user = $this->start([
            'goals' => ['subscribers'],
            'publish' => 'wait',
            'video_mode' => 'short',
            'format' => 'scene',
        ]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/youtube/footage', [
                'footage' => UploadedFile::fake()->create('dinner.mp4', 200, 'video/mp4'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('footage');
    }
}
