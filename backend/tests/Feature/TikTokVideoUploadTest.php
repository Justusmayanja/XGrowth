<?php

namespace Tests\Feature;

use App\Exceptions\TikTokVideoException;
use App\Models\ConnectedAccount;
use App\Models\TikTokClip;
use App\Models\TikTokClipVideo;
use App\Models\User;
use App\Services\TikTok\TikTokAllowanceService;
use App\Services\TikTok\TikTokDraftService;
use App\Services\TikTok\TikTokVideoProbe;
use App\Services\TikTok\TikTokVideoService;
use App\Support\TikTokCatalog;
use App\Support\TikTokContentPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class TikTokVideoUploadTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(TikTokContentPosting::DISK);
        $this->fakeProbe();
    }

    public function test_a_chunked_upload_stores_one_private_file_and_clears_the_blocker(): void
    {
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);

        $this->attach($user, $clip, 'Salt the water.mp4', 'video-bytes');

        $video = TikTokClipVideo::query()->where('clip_id', $clip->id)->first();
        $this->assertNotNull($video);
        $this->assertTrue($video->ready);
        $this->assertSame('Salt the water.mp4', $video->original_filename);
        $this->assertSame(strlen('video-bytes'), $video->size);
        $this->assertSame(1080, $video->width);
        Storage::disk(TikTokContentPosting::DISK)->assertExists($video->path);
        $this->assertTrue($clip->fresh()->package['render']['ready']);

        $this->actingAs($user, 'sanctum')
            ->get('/api/tiktok/clips/'.$clip->id.'/video')
            ->assertOk();
    }

    public function test_another_user_cannot_add_or_read_the_file(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $owner->id]);
        $this->attach($owner, $clip);

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/video', ['filename' => 'x.mp4', 'size' => 4])
            ->assertNotFound();
        $this->actingAs($other, 'sanctum')
            ->get('/api/tiktok/clips/'.$clip->id.'/video')
            ->assertNotFound();
    }

    public function test_a_file_that_is_too_long_is_rejected_and_not_stored(): void
    {
        $this->fakeProbe(['duration' => 660]);
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/video', ['filename' => 'long.mp4', 'size' => 4])
            ->assertOk();
        $this->putChunk($user, $clip, 'long');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/video/complete')
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'This file is 11 minutes long. TikTok clips from Mackie17 can be up to 10 minutes.');

        $this->assertSame(0, TikTokClipVideo::query()->count());
        $this->assertFalse($clip->fresh()->package['render']['ready']);
        $this->assertSame([], Storage::disk(TikTokContentPosting::DISK)->allFiles());
    }

    public function test_replacing_a_video_keeps_one_file(): void
    {
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $this->attach($user, $clip, 'first.mp4', 'aaaa');
        $this->attach($user, $clip, 'second.mp4', 'bbbb');

        $this->assertSame(1, TikTokClipVideo::query()->count());
        $video = TikTokClipVideo::query()->first();
        $this->assertSame('second.mp4', $video->original_filename);
        $this->assertCount(1, Storage::disk(TikTokContentPosting::DISK)->allFiles());
    }

    public function test_discard_and_account_deletion_remove_the_file(): void
    {
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $this->attach($user, $clip);
        $path = TikTokClipVideo::query()->first()->path;

        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$clip->id.'/discard')->assertOk();
        Storage::disk(TikTokContentPosting::DISK)->assertMissing($path);

        $again = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $this->attach($user, $again);
        $kept = TikTokClipVideo::query()->first()->path;
        $user->delete();
        Storage::disk(TikTokContentPosting::DISK)->assertMissing($kept);
    }

    public function test_the_sweep_drops_orphans_missing_files_and_old_drafts(): void
    {
        Storage::disk(TikTokContentPosting::DISK)->put('stray/orphan.mp4', 'x');
        $user = User::factory()->create();
        $missing = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $this->attach($user, $missing);
        $row = TikTokClipVideo::query()->first();
        Storage::disk(TikTokContentPosting::DISK)->delete($row->path);

        $old = TikTokClip::factory()->create([
            'user_id' => $user->id,
            'status' => TikTokCatalog::IN_DRAFTS,
            'drafted_at' => now()->subDays(8),
        ]);
        Storage::disk(TikTokContentPosting::DISK)->put($user->id.'/'.$old->id.'/source.mp4', 'kept-too-long');
        TikTokClipVideo::query()->create([
            'clip_id' => $old->id,
            'source' => 'user',
            'disk' => TikTokContentPosting::DISK,
            'path' => $user->id.'/'.$old->id.'/source.mp4',
            'original_filename' => 'old.mp4',
            'size' => 14,
            'ready' => true,
        ]);

        app(TikTokVideoService::class)->sweep();

        Storage::disk(TikTokContentPosting::DISK)->assertMissing('stray/orphan.mp4');
        Storage::disk(TikTokContentPosting::DISK)->assertMissing($user->id.'/'.$old->id.'/source.mp4');
        $this->assertFalse($row->fresh()->ready);
        $this->assertFalse($missing->fresh()->package['render']['ready']);
        $this->assertNotNull(TikTokClipVideo::query()->where('clip_id', $old->id)->first()->purged_at);
    }

    public function test_confirm_without_a_file_does_not_call_tiktok(): void
    {
        Http::fake();
        config(['services.tiktok.scopes' => 'user.info.basic,video.upload']);
        $user = User::factory()->create();
        $this->giveTikTokAllowance($user);
        $this->link($user);
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', TikTokCatalog::QUEUED);

        Http::assertNothingSent();
        $this->assertNull($clip->fresh()->tiktok_publish_id);
    }

    public function test_a_server_error_is_retried_and_a_client_error_is_not(): void
    {
        $user = User::factory()->create();
        $this->giveTikTokAllowance($user, 2);
        $this->link($user);
        config(['services.tiktok.scopes' => 'user.info.basic,video.upload']);
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $this->attach($user, $clip);

        $inits = 0;
        $puts = 0;
        Http::fake(function ($request) use (&$inits, &$puts, $clip) {
            if (str_contains($request->url(), 'inbox/video/init')) {
                $inits++;
                if ($inits <= 3) {
                    return Http::response(['error' => ['code' => 'internal']], 500);
                }

                return Http::response([
                    'data' => ['publish_id' => 'pub-1', 'upload_url' => 'https://upload.test/file'],
                    'error' => ['code' => 'ok'],
                ], 200);
            }
            $puts++;
            $this->assertSame('pub-1', $clip->fresh()->tiktok_publish_id);

            return Http::response(['error' => ['code' => 'invalid_param']], 400);
        });

        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')->assertOk();
        $failed = $clip->fresh();
        $this->assertSame(3, $inits);
        $this->assertSame(TikTokCatalog::UPLOAD_FAILED, $failed->status);
        $this->assertNull($failed->tiktok_publish_id);
        $queuedAt = $failed->queued_at->toIso8601String();
        $this->assertSame(1, app(TikTokAllowanceService::class)->status($user)['used']);

        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$clip->id.'/upload/retry')->assertOk();
        $this->assertSame(1, $puts);
        $this->assertSame('pub-1', $clip->fresh()->tiktok_publish_id);
        $this->assertSame($queuedAt, $clip->fresh()->queued_at->toIso8601String());
        $this->assertSame(1, app(TikTokAllowanceService::class)->status($user)['used']);
    }

    public function test_a_finished_upload_lands_in_drafts_and_a_stuck_one_is_swept(): void
    {
        config(['services.tiktok.scopes' => 'user.info.basic,video.upload']);
        $user = User::factory()->create();
        $this->giveTikTokAllowance($user);
        $this->link($user);
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $this->attach($user, $clip);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'inbox/video/init')) {
                return Http::response([
                    'data' => ['publish_id' => 'pub-ok', 'upload_url' => 'https://upload.test/file'],
                    'error' => ['code' => 'ok'],
                ], 200);
            }
            if ($request->method() === 'PUT') {
                return Http::response('', 201);
            }

            return Http::response([
                'data' => ['status' => 'SEND_TO_USER_INBOX'],
                'error' => ['code' => 'ok'],
            ], 200);
        });

        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')->assertOk();
        $sent = $clip->fresh();
        $this->assertSame(TikTokCatalog::IN_DRAFTS, $sent->status);
        $this->assertSame('pub-ok', $sent->tiktok_publish_id);
        $this->assertNotNull($sent->drafted_at);
        $this->assertNull($sent->upload_url);

        $stuck = TikTokClip::factory()->create([
            'user_id' => $user->id,
            'status' => TikTokCatalog::UPLOADING,
        ]);
        TikTokClip::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHours(3)]);
        app(TikTokDraftService::class)->sweepStuck();
        $this->assertSame(TikTokCatalog::UPLOAD_FAILED, $stuck->fresh()->status);

        $resume = TikTokClip::factory()->create([
            'user_id' => $user->id,
            'status' => TikTokCatalog::UPLOADING,
            'tiktok_publish_id' => 'pub-resume',
            'bytes_sent_at' => now()->subMinutes(40),
        ]);
        TikTokClip::query()->whereKey($resume->id)->update(['updated_at' => now()->subMinutes(40)]);
        $resume->video()->create([
            'source' => 'user',
            'disk' => TikTokContentPosting::DISK,
            'path' => 'resume.mp4',
            'original_filename' => 'resume.mp4',
            'size' => 4,
            'ready' => true,
        ]);
        Storage::disk(TikTokContentPosting::DISK)->put('resume.mp4', 'data');
        app(TikTokDraftService::class)->sweepStuck();
        $this->assertSame(TikTokCatalog::IN_DRAFTS, $resume->fresh()->status);
    }

    public function test_ffprobe_must_exist_before_a_file_is_accepted(): void
    {
        config(['services.tiktok.ffprobe' => '/tmp/ffprobe-missing']);
        $this->expectException(TikTokVideoException::class);
        $this->expectExceptionMessage("We couldn't check this video. Try again in a moment.");
        (new TikTokVideoProbe)->inspect('/tmp/not-a-video');
    }

    private function fakeProbe(array $overrides = []): void
    {
        $payload = array_merge([
            'format' => 'mov,mp4,m4a,3gp,3g2,mj2',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'duration' => 12.2,
            'width' => 1080,
            'height' => 1920,
        ], $overrides);
        $this->mock(TikTokVideoProbe::class, function ($mock) use ($payload) {
            $mock->shouldReceive('inspect')->andReturn($payload);
        });
    }

    private function attach(User $user, TikTokClip $clip, string $name = 'clip.mp4', string $bytes = 'video-bytes'): void
    {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/video', ['filename' => $name, 'size' => strlen($bytes)])
            ->assertOk()
            ->assertJsonPath('data.chunk_count', 1);
        $this->putChunk($user, $clip, $bytes);
        $saved = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/video/complete')
            ->assertOk();
        $this->assertArrayNotHasKey('upload_url', $saved->json('data'));
    }

    private function putChunk(User $user, TikTokClip $clip, string $bytes): void
    {
        $this->actingAs($user, 'sanctum')->call(
            'PUT',
            '/api/tiktok/clips/'.$clip->id.'/video/chunks/0',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
            $bytes,
        )->assertOk();
    }

    private function link(User $user): void
    {
        ConnectedAccount::query()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'oid-'.$user->id,
            'username' => 'Kitchen',
            'access_token' => 'act-plain',
            'refresh_token' => 'rft-plain',
            'token_expires_at' => now()->addDay(),
            'is_active' => true,
            'scopes' => 'user.info.basic,video.upload',
        ]);
    }

}
