<?php

namespace Tests\Feature;

use App\Models\TikTokClip;
use App\Models\TikTokClipVideo;
use App\Services\Storage\MediaStore;
use App\Services\TikTok\TikTokVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Tests\TestCase;

class TikTokMediaScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tiktok_disk_cannot_read_list_or_delete_outside_its_prefix(): void
    {
        Storage::fake('s3');
        Storage::set('media', Storage::disk('s3'));
        Storage::purge('tiktok_videos');

        $bucket = Storage::disk('s3');
        $bucket->put('youtube/secret.txt', 'leave this alone');
        $bucket->put('notes/outside.txt', 'also outside');
        $scoped = Storage::disk('tiktok_videos');
        $scoped->put('9/3/source.mp4', 'video');

        $this->assertSame(['9/3/source.mp4'], $scoped->allFiles());
        $this->assertFalse($scoped->exists('youtube/secret.txt'));
        $this->assertFalse($scoped->exists('notes/outside.txt'));
        $this->assertSame([], app(MediaStore::class)->listPrefix(''));

        foreach (['../youtube/secret.txt', '../notes/outside.txt'] as $escape) {
            try {
                $scoped->get($escape);
                $this->fail($escape.' was readable from the TikTok disk.');
            } catch (PathTraversalDetected) {
                // The prefix rejected the path.
            }
            try {
                $scoped->delete($escape);
                $this->fail($escape.' was deletable from the TikTok disk.');
            } catch (PathTraversalDetected) {
                // The prefix rejected the path.
            }
        }

        try {
            $scoped->get('youtube/secret.txt');
            $this->fail('A key outside tiktok/ was readable.');
        } catch (\League\Flysystem\UnableToReadFile) {
            // The scoped name does not point at the outside object.
        }
        $scoped->delete('youtube/secret.txt');
        $scoped->delete('9/3/source.mp4');

        $this->assertTrue($bucket->exists('youtube/secret.txt'));
        $this->assertTrue($bucket->exists('notes/outside.txt'));
        $this->assertFalse($bucket->exists('tiktok/9/3/source.mp4'));
        $left = $bucket->allFiles();
        sort($left);
        $this->assertSame(['notes/outside.txt', 'youtube/secret.txt'], $left);
    }

    public function test_dry_run_migration_and_sweeps_only_touch_tiktok_keys_on_the_fake_bucket(): void
    {
        Storage::fake('s3');
        Storage::set('media', Storage::disk('s3'));
        Storage::purge('tiktok_videos');

        $legacy = storage_path('framework/testing/disks/tiktok-legacy');
        config(['filesystems.disks.tiktok_videos_local.root' => $legacy]);
        Storage::purge('tiktok_videos_local');

        $clip = TikTokClip::factory()->create();
        $key = $clip->user_id.'/'.$clip->id.'/source.mp4';
        $bytes = 'legacy-video-bytes';
        Storage::disk('tiktok_videos_local')->put($key, $bytes);
        TikTokClipVideo::query()->create([
            'clip_id' => $clip->id,
            'source' => 'user',
            'disk' => 'tiktok_videos',
            'path' => $key,
            'original_filename' => 'clip.mp4',
            'size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'ready' => true,
        ]);

        $bucket = Storage::disk('s3');
        $bucket->put('youtube/secret.txt', 'leave this alone');
        $bucket->put('tiktok/'.$clip->user_id.'/stray.mp4', 'orphan');
        $bucket->put('tiktok/'.$key, 'already stored');
        $before = $bucket->allFiles();
        sort($before);

        $this->artisan('tiktok:migrate-videos', ['--from' => 'local', '--to' => 'media', '--dry-run' => true])
            ->expectsOutputToContain('The source files were left in place.')
            ->assertSuccessful();

        $afterDryRun = $bucket->allFiles();
        sort($afterDryRun);
        $this->assertSame($before, $afterDryRun);
        $this->assertSame($bytes, Storage::disk('tiktok_videos_local')->get($key));

        app(TikTokVideoService::class)->sweep();

        $after = $bucket->allFiles();
        $this->assertNotContains('tiktok/'.$clip->user_id.'/stray.mp4', $after);
        $this->assertContains('youtube/secret.txt', $after);
        $this->assertContains('tiktok/'.$key, $after);
        foreach (array_diff($before, $after) as $removed) {
            $this->assertStringStartsWith('tiktok/', $removed);
        }
        $this->assertSame('leave this alone', $bucket->get('youtube/secret.txt'));
    }
}
