<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\YouTubeVideo;
use App\Services\YouTube\YouTubeFfmpegRenderer;
use App\Services\YouTube\YouTubeMediaInspector;
use App\Services\YouTube\YouTubeRenderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class YouTubeRenderTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT = <<<'TXT'
0:00 Cheap weeknight meals finish when the shortcut is the meal itself.
0:08 The shop is the part that feels like work.
0:16 But the pan you already own is the part that finishes.
0:28 That is the shortcut, and the plate is the proof.
0:40 A second sitting is the same pan, not a new plan.
0:52 The line to remember is the meal finishes in the pan you have.
1:00 The leftovers are the part people skip.
1:10 But a second night uses the same pan.
1:22 That second night is the proof the shortcut holds.
1:36 The meal finishes again.
TXT;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['openai.api_key' => '']);
    }

    public function test_a_missing_binary_leaves_waiting_and_queued_videos_unsent(): void
    {
        $waiting = $this->videoUser('wait');
        $queued = $this->videoUser('auto');

        $waitingResponse = $this->actingAs($waiting, 'sanctum')
            ->postJson('/api/youtube/videos/'.$this->onlyVideo($waiting)->id.'/render')
            ->assertOk();
        $queuedResponse = $this->actingAs($queued, 'sanctum')
            ->postJson('/api/youtube/videos/'.$this->onlyVideo($queued)->id.'/render')
            ->assertOk();

        $waitingVideo = $this->onlyVideo($waiting);
        $queuedVideo = $this->onlyVideo($queued);
        $this->assertSame('waiting', $waitingVideo->status);
        $this->assertSame('queued', $queuedVideo->status);
        foreach ([$waitingVideo, $queuedVideo] as $video) {
            $this->assertSame('pending', $video->package['render']['provider']);
            $this->assertFalse($video->package['render']['ready']);
            $this->assertArrayNotHasKey('path', $video->package['render']);
            $this->assertStringContainsString('ffmpeg', (string) $video->package['render']['note']);
            $this->assertStringContainsString('Nothing is uploaded', (string) $video->package['render']['note']);
            $this->assertFalse($video->package['has_transcript'] ?? false);
        }
        $waitingResponse->assertJsonPath('pipeline.0.status', 'waiting');
        $queuedResponse->assertJsonPath('pipeline.0.status', 'queued');
        $this->assertStringNotContainsString('posted', strtolower((string) $waitingResponse->json('pipeline.0.package.render.note')));
    }

    public function test_footage_stays_awaiting_edit_when_ffmpeg_is_missing(): void
    {
        Storage::fake('local');
        $user = $this->start([
            'goals' => ['subscribers'],
            'publish' => 'wait',
            'video_mode' => 'footage',
        ]);
        $this->actingAs($user, 'sanctum')->post('/api/youtube/footage', [
            'footage' => UploadedFile::fake()->create('dinner.mp4', 200, 'video/mp4'),
            'note' => 'Start with the onion. Then the cheap cut.',
        ])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$this->onlyVideo($user)->id.'/render')
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'awaiting_edit')
            ->assertJsonPath('pipeline.0.package.render.ready', false)
            ->assertJsonPath('pipeline.0.package.render.provider', 'pending');

        $this->assertSame('awaiting_edit', $this->onlyVideo($user)->status);
    }

    public function test_writing_a_package_does_not_render_it(): void
    {
        $this->mock(YouTubeFfmpegRenderer::class, function ($mock) {
            $mock->shouldNotReceive('render');
        });
        $user = $this->videoUser('wait');

        $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/platform/youtube')->assertOk();

        $video = $this->onlyVideo($user);
        $this->assertSame('waiting', $video->status);
        $this->assertFalse($video->package['render']['ready']);
        $this->assertSame('pending', $video->package['render']['provider']);
    }

    public function test_a_rejected_file_is_not_marked_ready_and_the_status_stays(): void
    {
        $user = $this->videoUser('wait');
        $video = $this->onlyVideo($user);
        $this->mockRenderer();
        $this->mock(YouTubeMediaInspector::class, function ($mock) {
            $mock->shouldReceive('accept')->once()->andThrow(
                new YouTubeRenderException('The local file did not pass checks, so it was not saved. Nothing is uploaded.')
            );
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/render')
            ->assertOk()
            ->assertJsonPath('pipeline.0.package.render.ready', false)
            ->assertJsonPath('pipeline.0.package.render.provider', 'pending');

        $video->refresh();
        $this->assertSame('waiting', $video->status);
        $this->assertStringContainsString('did not pass checks', (string) $video->package['render']['note']);
        $this->assertArrayNotHasKey('path', $video->package['render']);
    }

    public function test_a_validated_file_can_be_previewed_without_uploading_and_a_transcript_regenerates_shorts(): void
    {
        config([
            'services.youtube.transcription_driver' => 'fake',
            'services.youtube.fake_transcript' => self::TRANSCRIPT,
        ]);
        $user = $this->videoUser('wait');
        $video = $this->onlyVideo($user);
        $this->mockRenderer();
        $this->mock(YouTubeMediaInspector::class, function ($mock) {
            $mock->shouldReceive('accept')->once()->andReturn([
                'format' => 'mp4',
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'duration' => 12.5,
                'width' => 1920,
                'height' => 1080,
            ]);
        });

        $desk = $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/render')
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'waiting')
            ->assertJsonPath('pipeline.0.package.render.ready', true)
            ->assertJsonPath('pipeline.0.package.render.provider', 'ffmpeg')
            ->assertJsonPath('pipeline.0.package.has_transcript', true);

        $this->assertNotEmpty($desk->json('pipeline.0.package.shorts'));
        $video->refresh();
        $this->assertSame('waiting', $video->status);
        $this->assertSame('mp4', $video->package['render']['extension']);
        $this->assertNotSame('', (string) $video->package['render']['path']);
        Storage::disk('youtube_media')->assertExists($video->package['render']['path']);
        Storage::disk('youtube_media')->assertExists($video->package['render']['preview_path']);

        $media = $this->actingAs($user, 'sanctum')
            ->getJson('/api/youtube/videos/'.$video->id.'/media')
            ->assertOk()
            ->json('data');
        $this->assertStringContainsString('Nothing has been uploaded', (string) $media['note']);
        $this->get($media['preview_url'])->assertOk();
        $this->get($media['download_url'])
            ->assertOk()
            ->assertDownload('youtube-'.$video->id.'.mp4');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/transcript', [
                'transcript' => "0:00 Hello there.\n0:04 Still here.\n",
            ])
            ->assertOk()
            ->assertJsonPath('pipeline.0.package.has_transcript', true)
            ->assertJsonPath('pipeline.0.package.shorts', [])
            ->assertJsonPath('pipeline.0.status', 'waiting');

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->getJson('/api/youtube/videos/'.$video->id.'/media')
            ->assertNotFound();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/render')
            ->assertNotFound();

        Storage::disk('youtube_media')->deleteDirectory($user->id.'/'.$video->id);
    }

    public function test_learned_videos_and_other_owners_are_refused(): void
    {
        $user = $this->videoUser('wait');
        $video = $this->onlyVideo($user);
        $this->actingAs($user, 'sanctum')->postJson('/api/youtube/videos/'.$video->id.'/results', [
            'views' => 10,
            'average_view_percent' => 40,
        ])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/render')
            ->assertStatus(422);

        $video->refresh();
        $this->assertSame('learned', $video->status);
        $this->assertFalse($video->package['render']['ready']);

        $owner = $this->videoUser('wait');
        $owned = $this->onlyVideo($owner);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$owned->id.'/render')
            ->assertNotFound();
        $this->assertSame('waiting', $owned->fresh()->status);
    }

    public function test_media_is_hidden_until_a_file_passes_checks(): void
    {
        $user = $this->videoUser('wait');
        $video = $this->onlyVideo($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/youtube/videos/'.$video->id.'/media')
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $youtube
     */
    private function start(array $youtube): User
    {
        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/account/setup/platforms', ['platforms' => ['youtube']])->assertOk();
        $api->postJson('/api/account/setup/about', [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and share the shortcuts that actually finish.',
            'audience' => 'Students cooking for one',
            'tone' => ['funny'],
        ])->assertOk();
        $api->postJson('/api/account/setup/platforms/youtube', $youtube)->assertOk();

        return $user;
    }

    private function videoUser(string $publish): User
    {
        $user = $this->start([
            'goals' => ['subscribers', 'search'],
            'publish' => $publish,
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ]);
        $this->actingAs($user, 'sanctum')->postJson('/api/youtube/listings', [
            'videos' => [
                ['channel' => 'Weeknight', 'title' => 'Cheap weeknight soup', 'views' => 1000],
                ['channel' => 'Weeknight', 'title' => 'Another cheap weeknight', 'views' => 1200],
                ['channel' => 'Weeknight', 'title' => 'Weeknight notes', 'views' => 800],
                ['channel' => 'Weeknight', 'title' => 'How do cheap weeknight meals finish?', 'views' => 9000],
            ],
        ])->assertOk();

        return $user;
    }

    private function onlyVideo(User $user): YouTubeVideo
    {
        return YouTubeVideo::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function mockRenderer(): void
    {
        $this->mock(YouTubeFfmpegRenderer::class, function ($mock) {
            $mock->shouldReceive('available')->andReturn(true);
            $mock->shouldReceive('kind')->andReturn('text');
            $mock->shouldReceive('render')->andReturnUsing(function ($video, string $work) {
                foreach ([
                    'render.mp4' => 'video-bytes',
                    'preview.mp4' => 'preview-bytes',
                    'poster.jpg' => 'poster-bytes',
                ] as $name => $body) {
                    file_put_contents($work.DIRECTORY_SEPARATOR.$name, $body);
                }

                return [
                    'video' => $work.DIRECTORY_SEPARATOR.'render.mp4',
                    'preview' => $work.DIRECTORY_SEPARATOR.'preview.mp4',
                    'poster' => $work.DIRECTORY_SEPARATOR.'poster.jpg',
                    'detail' => null,
                ];
            });
        });
    }
}
