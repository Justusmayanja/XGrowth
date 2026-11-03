<?php

namespace App\Console\Commands;

use App\Models\TikTokClipVideo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateTikTokVideos extends Command
{
    protected $signature = 'tiktok:migrate-videos {--from=local} {--to=s3} {--dry-run}';

    protected $description = 'Copy TikTok videos onto the shared media disk after the checksum matches';

    public function handle(): int
    {
        $from = $this->option('from') === 'local' ? 'tiktok_videos_local' : (string) $this->option('from');
        $to = $this->option('to') === 's3' ? 'tiktok_videos' : (string) $this->option('to');
        $dry = (bool) $this->option('dry-run');

        if (! config('filesystems.disks.'.$from) || ! config('filesystems.disks.'.$to)) {
            $this->error('Those disks are not configured.');

            return self::FAILURE;
        }

        $source = Storage::disk($from);
        $dest = Storage::disk($to);
        $copied = 0;
        $skipped = 0;

        TikTokClipVideo::query()->whereNotNull('path')->whereNull('purged_at')->orderBy('id')->each(function (TikTokClipVideo $video) use ($source, $dest, $dry, &$copied, &$skipped) {
            $key = (string) $video->path;
            if (! $source->exists($key)) {
                $this->line('missing '.$video->id);
                $skipped++;

                return;
            }

            $bytes = $source->get($key);
            $checksum = hash('sha256', $bytes);
            if (is_string($video->checksum) && $video->checksum !== '' && ! hash_equals($video->checksum, $checksum)) {
                $this->line('checksum '.$video->id);
                $skipped++;

                return;
            }

            if ($dry) {
                $this->line('would copy '.$video->id);
                $copied++;

                return;
            }

            $dest->put($key, $bytes);
            $stored = $dest->get($key);
            if (! is_string($stored) || ! hash_equals($checksum, hash('sha256', $stored))) {
                $dest->delete($key);
                $this->line('verify '.$video->id);
                $skipped++;

                return;
            }

            $copied++;
        });

        $this->info("Copied {$copied}. Skipped {$skipped}. The source files were left in place.");

        return self::SUCCESS;
    }
}
