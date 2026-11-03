<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\PlatformSetup;
use App\Models\User;
use App\Services\TikTok\FfmpegRunner;
use App\Services\TikTok\TikTokEngineService;
use App\Services\TikTok\TikTokScriptWriter;
use App\Services\TikTok\TikTokVideoProbe;
use App\Services\TikTok\TikTokVideoService;
use App\Support\TikTokCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

#[Group('ffmpeg')]
class TikTokFfmpegRenderTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    public function test_fake_voice_run_renders_a_ready_preview_that_passes_the_upload_validator(): void
    {
        $ffmpeg = getenv('HOME').'/.local/bin/ffmpeg';
        $ffprobe = getenv('HOME').'/.local/bin/ffprobe';
        if (! is_executable($ffmpeg) || ! is_executable($ffprobe)) {
            $this->markTestSkipped('The apt ffmpeg build is not available.');
        }

        config([
            'openai.api_key' => '',
            'tiktok_video.tts_driver' => 'fake',
            'services.tiktok.ffmpeg' => $ffmpeg,
            'services.tiktok.ffprobe' => $ffprobe,
        ]);

        $runner = app(FfmpegRunner::class);
        if (! $runner->available() || ! $runner->hasFilter('ass')) {
            $this->markTestSkipped('ffmpeg is present but libass is not.');
        }

        $lines = [
            'Salt the water before the pasta goes in.',
            '80% of the flavour is already in that water.',
            'First, taste it before the pasta goes in.',
            'Is the water salty enough?',
            'Then stop and plate the bowl.',
        ];
        $script = [
            'topic' => 'Salt the pasta water',
            'idea_kind' => 'tip',
            'hook' => $lines[0],
            'spoken_lines' => $lines,
            'caption' => $lines[0]."\nTaste it first.\n#budgetcooking #weeknights #pasta",
            'on_screen_text' => [$lines[0]],
            'shots' => ['Pot on the stove', 'Salt in the hand', 'A taste from the spoon'],
            'sound_note' => 'Voice only.',
            'source' => TikTokCatalog::SOURCE_FALLBACK,
        ];
        $this->mock(TikTokScriptWriter::class, function ($mock) use ($script) {
            $mock->shouldReceive('clip')->once()->andReturn($script);
            $mock->shouldReceive('fallback')->andReturn($script);
        });

        $user = $this->readyUser();
        $result = app(TikTokEngineService::class)->run($user);

        $this->assertSame(TikTokCatalog::CREATED, $result['outcome']);
        $clip = $result['clip']->fresh(['video']);
        $this->assertSame(TikTokCatalog::WAITING, $clip->status);
        $this->assertTrue($clip->package['render']['ready']);
        $this->assertSame('ffmpeg', $clip->package['render']['provider']);
        $this->assertTrue($clip->package['render']['ai_generated']);
        $this->assertNotNull($clip->video);
        $this->assertSame('generated', $clip->video->source);
        $this->assertTrue($clip->video->ready);

        $preview = $this->actingAs($user, 'sanctum')
            ->getJson('/api/tiktok/clips/'.$clip->id.'/video/preview')
            ->assertOk()
            ->json('data');
        $this->assertNotSame('', $preview['preview_url']);
        $this->assertStringContainsString('signature=', (string) $preview['preview_url']);
        $this->get($preview['preview_url'])->assertOk();

        $disk = Storage::disk('tiktok_videos');
        $source = $disk->path($clip->video->path);
        $previewPath = $disk->path($clip->video->preview_path);
        $poster = $disk->path($clip->video->poster_path);
        $this->assertFileExists($source);
        $this->assertFileExists($previewPath);
        $this->assertFileExists($poster);

        $probe = app(TikTokVideoProbe::class)->inspect($source);
        $accepted = app(TikTokVideoService::class)->acceptProbe($probe);
        $this->assertSame('h264', $probe['video_codec']);
        $this->assertSame('aac', $probe['audio_codec']);
        $this->assertNull($accepted['warning']);
        $this->assertGreaterThan(0, $accepted['width']);

        $review = '/tmp/phase9-review';
        if (! is_dir($review)) {
            mkdir($review, 0755, true);
        }
        copy($source, $review.'/source.mp4');
        copy($previewPath, $review.'/preview.mp4');
        copy($poster, $review.'/poster.jpg');
        $kinds = array_map(fn ($scene) => $scene['kind'] ?? '', $clip->package['scenes'] ?? []);
        file_put_contents($review.'/manifest.json', json_encode([
            'source' => $review.'/source.mp4',
            'preview' => $review.'/preview.mp4',
            'poster' => $review.'/poster.jpg',
            'stored_source' => $source,
            'kinds' => $kinds,
            'lines' => $lines,
            'probe' => $probe,
            'validator' => $accepted,
            'preview_url' => $preview['preview_url'],
            'preview_bytes' => $clip->video->preview_bytes,
            'source_bytes' => $clip->video->size,
        ], JSON_PRETTY_PRINT));

        $this->assertSame(['statement', 'stat', 'list_item', 'qa', 'outro'], array_values($kinds));
    }

    private function readyUser(): User
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'tiktok',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'timezone' => 'Africa/Kampala',
            'onboarding_completed' => true,
            'discovery' => ['presence' => 'faceless'],
        ]);
        PlatformSetup::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'goals' => 'for_you',
            'settings' => ['publish' => 'confirm', 'video' => ['generate' => 'ahead', 'type' => 'motion']],
            'completed_at' => now(),
        ]);
        $this->giveTikTokAllowance($user);

        return $user->fresh('accountProfile');
    }
}
