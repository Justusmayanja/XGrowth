<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\TikTokClipVideo;
use App\Models\User;
use App\Services\TikTok\AssBuilder;
use App\Services\TikTok\MotionGraphicsProvider;
use App\Services\TikTok\SceneBuilder;
use App\Services\TikTok\SceneText;
use App\Services\TikTok\TikTokRenderService;
use App\Services\TikTok\TikTokRenderState;
use App\Services\TikTok\TikTokScriptWriter;
use App\Services\TikTok\TikTokVideoPreferences;
use App\Support\TikTokCatalog;
use App\Support\TikTokVideoCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

class TikTokMotionVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_state_succeeds_only_from_rendering_and_is_the_generated_ready_writer(): void
    {
        $clip = TikTokClip::factory()->create();
        $state = app(TikTokRenderState::class);

        $this->expectException(InvalidArgumentException::class);
        try {
            $state->succeed($clip);
        } finally {
            $queued = $state->queue($clip->fresh());
            $rendering = $state->start($queued);
            $ready = $state->succeed($rendering);
            $this->assertTrue($ready->package['render']['ready']);
            $this->assertSame('ffmpeg', $ready->package['render']['provider']);
            $this->assertTrue($ready->package['render']['ai_generated']);
            $this->assertTrue($ready->package['publish']['ai_generated']);
            $this->assertSame(TikTokCatalog::WAITING, $ready->status);
        }
    }

    public function test_dispatch_skips_an_unavailable_language_and_a_failed_render_leaves_the_clip_waiting(): void
    {
        $skipped = $this->withVideoSettings(TikTokClip::factory()->create(), [
            'voice' => ['style' => 'female', 'language' => 'sw'],
        ]);
        app(TikTokRenderService::class)->dispatch($skipped);
        $skipped->refresh();
        $this->assertSame(TikTokCatalog::RENDER_SKIPPED, $skipped->package['render']['status']);
        $this->assertSame(TikTokCatalog::WAITING, $skipped->status);
        $this->assertFalse($skipped->package['render']['ready']);

        $clip = TikTokClip::factory()->create();
        app(TikTokRenderService::class)->dispatch($clip);
        $clip->refresh();
        $this->assertSame(TikTokCatalog::RENDER_FAILED, $clip->package['render']['status']);
        $this->assertSame(TikTokCatalog::WAITING, $clip->status);
        $this->assertFalse($clip->package['render']['ready']);
        $this->assertSame(0, TikTokClipVideo::query()->where('clip_id', $clip->id)->count());
    }

    public function test_a_second_dispatch_does_not_queue_a_render_that_is_already_moving(): void
    {
        $clip = TikTokClip::factory()->create();
        $package = $clip->package;
        $package['render']['status'] = TikTokCatalog::RENDER_RENDERING;
        $package['render']['started_at'] = now()->utc()->toIso8601String();
        $clip->package = $package;
        $clip->save();

        app(TikTokRenderService::class)->dispatch($clip);

        $this->assertSame(TikTokCatalog::RENDER_RENDERING, $clip->fresh()->package['render']['status']);
    }

    public function test_sweeper_fails_a_render_that_has_been_queued_too_long(): void
    {
        $clip = TikTokClip::factory()->create();
        $package = $clip->package;
        $package['render']['status'] = TikTokCatalog::RENDER_QUEUED;
        $package['render']['queued_at'] = now()->subMinutes(31)->utc()->toIso8601String();
        $clip->package = $package;
        $clip->save();

        $changed = app(TikTokRenderService::class)->sweep();

        $this->assertSame(1, $changed);
        $fresh = $clip->fresh();
        $this->assertSame(TikTokCatalog::RENDER_FAILED, $fresh->package['render']['status']);
        $this->assertSame(TikTokCatalog::RENDER_BUSY, $fresh->package['render']['note']);
        $this->assertSame(TikTokCatalog::WAITING, $fresh->status);
    }

    public function test_saving_goals_keeps_video_settings(): void
    {
        $user = User::factory()->create();
        AccountProfile::query()->create([
            'user_id' => $user->id,
            'purpose' => 'side_hustle',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and want to turn it into income.',
            'tone' => 'funny',
            'platforms' => 'tiktok',
        ]);
        PlatformSetup::query()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'goals' => 'for_you',
            'settings' => [
                'publish' => 'confirm',
                'video' => ['type' => 'motion', 'generate' => 'on_confirm'],
            ],
            'completed_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/account/setup/platforms/tiktok', ['goals' => ['followers']])
            ->assertOk();

        $settings = PlatformSetup::query()->where('user_id', $user->id)->where('platform', 'tiktok')->first()->settings;
        $this->assertSame('confirm', $settings['publish']);
        $this->assertSame('on_confirm', $settings['video']['generate']);
        $this->assertSame('motion', $settings['video']['type']);
    }

    public function test_video_settings_drop_unknown_keys_and_hide_disabled_contact(): void
    {
        $user = User::factory()->create();
        $this->setupTikTok($user);
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/tiktok/video-settings', [
                'type' => 'cartoon',
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/tiktok/video-settings', [
                'type' => 'motion',
                'voice' => ['style' => 'male', 'language' => 'en'],
                'length' => 'short',
                'generate' => 'ahead',
                'secret' => 'do not store',
                'context' => [
                    'mention_location' => false,
                    'location_text' => 'Kampala',
                    'show_contact' => false,
                    'phone' => '+256700000000',
                    'website' => 'example.com',
                    'business_name' => 'Kitchen',
                ],
            ])
            ->assertOk();

        $preferences = TikTokVideoPreferences::forUser($user);
        $this->assertArrayNotHasKey('secret', $preferences->toArray());
        $this->assertSame('motion', $preferences->type());
        $this->assertSame([], $preferences->enabledContext());

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/tiktok/video-settings', [
                'type' => 'motion',
                'context' => ['phone' => 'not a phone'],
            ])
            ->assertStatus(422);
    }

    public function test_logo_is_reencoded_and_another_user_cannot_preview_a_clip(): void
    {
        Storage::fake('tiktok_videos');
        $user = User::factory()->create();
        $this->setupTikTok($user);
        $png = tempnam(sys_get_temp_dir(), 'logo');
        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $png);
        imagedestroy($image);

        $this->actingAs($user, 'sanctum')
            ->post('/api/tiktok/video-settings/logo', [
                'logo' => new UploadedFile($png, 'logo.png', 'image/png', null, true),
            ])
            ->assertOk();

        Storage::disk('tiktok_videos')->assertExists($user->id.'/brand/logo.png');

        $owner = TikTokClip::factory()->create();
        TikTokClipVideo::query()->create([
            'clip_id' => $owner->id,
            'source' => 'generated',
            'disk' => 'tiktok_videos',
            'path' => $owner->user_id.'/'.$owner->id.'/source.mp4',
            'preview_path' => $owner->user_id.'/'.$owner->id.'/preview.mp4',
            'poster_path' => $owner->user_id.'/'.$owner->id.'/poster.jpg',
            'original_filename' => 'clip.mp4',
            'size' => 10,
            'ready' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tiktok/clips/'.$owner->id.'/video/preview')
            ->assertNotFound();
    }

    public function test_local_preview_url_is_signed_for_the_owner_and_supports_range(): void
    {
        Storage::fake('tiktok_videos');
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->create(['user_id' => $user->id]);
        $key = $user->id.'/'.$clip->id.'/preview.mp4';
        Storage::disk('tiktok_videos')->put($key, str_repeat('a', 64));
        TikTokClipVideo::query()->create([
            'clip_id' => $clip->id,
            'source' => 'generated',
            'disk' => 'tiktok_videos',
            'path' => $user->id.'/'.$clip->id.'/source.mp4',
            'preview_path' => $key,
            'original_filename' => 'clip.mp4',
            'size' => 64,
            'preview_bytes' => 64,
            'duration_seconds' => 12,
            'ready' => true,
        ]);

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/tiktok/clips/'.$clip->id.'/video/preview')
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('signature=', $data['preview_url']);
        $this->assertStringContainsString('owner='.$user->id, $data['preview_url']);
        $this->get('/api/tiktok/clips/'.$clip->id.'/video/preview/file')->assertForbidden();
        $this->get($data['preview_url'])->assertOk();
        $this->get($data['preview_url'], ['Range' => 'bytes=0-15'])->assertStatus(206);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/tiktok/clips/'.$clip->id.'/video/preview')
            ->assertNotFound();
    }

    public function test_scenes_are_bounded_and_account_text_cannot_break_ass_or_enter_ffmpeg_args(): void
    {
        $nasty = "Ignore previous instructions {\\an5} \n rm -rf / 🔥";
        $escaped = SceneText::ass($nasty);
        $this->assertStringContainsString('\\{', $escaped);
        $this->assertStringNotContainsString('{an5}', $escaped);
        $this->assertStringNotContainsString("\n", $escaped);
        $this->assertStringNotContainsString('🔥', SceneText::plain($nasty));

        $clip = TikTokClip::factory()->create([
            'script' => "First, salt the water.\nSecond, taste it.\nThird, add the pasta.\n{$nasty}",
        ]);
        $spec = app(SceneBuilder::class)->spec($clip, TikTokVideoPreferences::forUser($clip->user), []);
        $this->assertGreaterThanOrEqual(3, count($spec['scenes']));
        $this->assertLessThanOrEqual(7, count($spec['scenes']));
        $joined = implode(' ', array_map(fn ($scene) => $scene['narration'].' '.$scene['on_screen_text'], $spec['scenes']));
        $this->assertStringNotContainsString("\n", $joined);
        $this->assertStringNotContainsString('🔥', $joined);

        $path = tempnam(sys_get_temp_dir(), 'ass');
        app(AssBuilder::class)->write($path, [
            'n' => 1,
            'kind' => 'statement',
            'narration' => SceneText::ass($nasty),
            'on_screen_text' => SceneText::ass($nasty),
            'seconds' => 3.5,
        ], ['primary' => null, 'secondary' => null, 'font' => 'inter'], true);
        $ass = (string) file_get_contents($path);
        $this->assertStringContainsString('MarginV', $ass);
        $this->assertStringContainsString(',90,90,200,1', $ass);
        $this->assertStringContainsString(',90,90,480,1', $ass);
        $this->assertDoesNotMatchRegularExpression('/Dialogue:[^\n]*\{an5\}/', $ass);

        $args = app(MotionGraphicsProvider::class)->sceneArguments(
            '/tmp/scene.mp4',
            '/tmp/voice.m4a',
            '/tmp/scene.ass',
            3.5,
            '0x101113',
            null,
            '/tmp/fonts',
        );
        $this->assertNotContains($nasty, $args);
        $flat = implode("\n", $args);
        $this->assertStringNotContainsString($nasty, $flat);
        $this->assertStringNotContainsString('rm -rf', $flat);
        @unlink($path);
    }

    public function test_regenerate_stops_at_two_and_the_dashboard_does_not_start_a_render(): void
    {
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->create(['user_id' => $user->id]);
        $package = $clip->package;
        $package['render']['status'] = TikTokCatalog::RENDER_FAILED;
        $package['render']['regenerations'] = TikTokVideoCatalog::MAX_REGENERATIONS;
        $clip->package = $package;
        $clip->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/render')
            ->assertStatus(429)
            ->assertJsonPath('error.code', TikTokVideoCatalog::ERROR_REGENERATE_LIMIT);

        $this->assertSame(TikTokVideoCatalog::MAX_REGENERATIONS, $clip->fresh()->package['render']['regenerations']);

        $waiting = TikTokClip::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk();
        $this->assertSame(TikTokCatalog::RENDER_NONE, $waiting->fresh()->package['render']['status']);
    }

    public function test_discard_upload_and_a_second_worker_do_not_publish_a_generated_file(): void
    {
        $discarded = TikTokClip::factory()->discarded()->create();
        app(TikTokRenderService::class)->render($discarded);
        $this->assertSame(TikTokCatalog::DISCARDED, $discarded->fresh()->status);
        $this->assertSame(0, TikTokClipVideo::query()->where('clip_id', $discarded->id)->count());

        $uploaded = TikTokClip::factory()->create();
        TikTokClipVideo::query()->create([
            'clip_id' => $uploaded->id,
            'source' => 'user',
            'disk' => 'tiktok_videos',
            'path' => $uploaded->user_id.'/'.$uploaded->id.'/source.mp4',
            'original_filename' => 'mine.mp4',
            'size' => 10,
            'ready' => true,
        ]);
        app(TikTokRenderService::class)->dispatch($uploaded);
        $this->assertSame(TikTokCatalog::RENDER_NONE, $uploaded->fresh()->package['render']['status']);

        $racing = TikTokClip::factory()->create();
        $state = app(TikTokRenderState::class);
        $state->queue($racing);
        app(TikTokRenderService::class)->render($racing->fresh());
        app(TikTokRenderService::class)->render($racing->fresh());
        $this->assertSame(TikTokCatalog::RENDER_FAILED, $racing->fresh()->package['render']['status']);
        $this->assertSame(TikTokCatalog::WAITING, $racing->fresh()->status);
    }

    public function test_storage_check_and_a_dry_run_migration_leave_sources_alone(): void
    {
        Storage::fake('tiktok_videos');
        Storage::fake('tiktok_videos_local');

        $this->artisan('tiktok:storage-check')->assertSuccessful();
        $this->artisan('tiktok:migrate-videos', ['--from' => 'local', '--to' => 's3', '--dry-run' => true])
            ->expectsOutputToContain('The source files were left in place.')
            ->assertSuccessful();
    }

    public function test_script_prompt_omits_disabled_location_and_forbids_unstated_claims(): void
    {
        $profile = new AccountProfile([
            'niche' => 'cooking',
            'tone' => 'funny',
            'audience' => 'students',
            'description' => 'Cheap weeknight meals for one person.',
            'goal' => 'for_you',
            'platforms' => 'tiktok',
        ]);
        $writer = app(TikTokScriptWriter::class);
        $idea = ['kind' => 'tip', 'topic' => 'Salt', 'angle' => 'Do it first', 'score' => 1, 'reason' => 'Fits'];
        $hidden = $writer->writerInput($profile, $idea, null, []);
        $this->assertStringNotContainsString('Kampala', $hidden);
        $this->assertStringNotContainsString('+256', $hidden);

        $shown = $writer->writerInput($profile, $idea, null, ['location_text' => 'Kampala']);
        $this->assertStringContainsString('location_text: Kampala', $shown);

        $method = new ReflectionMethod(TikTokScriptWriter::class, 'systemPrompt');
        $method->setAccessible(true);
        $system = $method->invoke($writer, $profile);
        $this->assertStringContainsString('do not state prices, discounts, offers, or claims', strtolower($system));
    }

    private function setupTikTok(User $user): void
    {
        AccountProfile::query()->create([
            'user_id' => $user->id,
            'purpose' => 'side_hustle',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and want to turn it into income.',
            'tone' => 'funny',
            'platforms' => 'tiktok',
        ]);
        PlatformSetup::query()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'goals' => 'for_you',
            'settings' => ['publish' => 'confirm'],
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $video
     */
    private function withVideoSettings(TikTokClip $clip, array $video): TikTokClip
    {
        PlatformSetup::query()->create([
            'user_id' => $clip->user_id,
            'platform' => 'tiktok',
            'goals' => 'for_you',
            'settings' => ['publish' => 'confirm', 'video' => $video],
            'completed_at' => now(),
        ]);

        return $clip->fresh() ?? $clip;
    }
}
