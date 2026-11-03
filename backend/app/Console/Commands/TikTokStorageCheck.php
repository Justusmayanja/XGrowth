<?php

namespace App\Console\Commands;

use App\Services\Storage\MediaStore;
use App\Support\TikTokContentPosting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TikTokStorageCheck extends Command
{
    protected $signature = 'tiktok:storage-check';

    protected $description = 'Write, read, and delete a private canary object on the TikTok media disk';

    public function handle(MediaStore $media): int
    {
        $disk = Storage::disk(TikTokContentPosting::DISK);
        $key = 'health/canary-'.bin2hex(random_bytes(4)).'.txt';
        $body = 'mackie17-storage-check';
        $failed = false;

        $write = $this->step('write', function () use ($disk, $key, $body) {
            $disk->put($key, $body);
        });
        $failed = $failed || ! $write;

        $read = $this->step('read', function () use ($disk, $key, $body) {
            if ($disk->get($key) !== $body) {
                throw new \RuntimeException('The canary did not match.');
            }
        });
        $failed = $failed || ! $read;

        if ($media->supportsTemporaryUrls()) {
            $signed = $this->step('signed-url', function () use ($media, $key) {
                $url = $media->temporaryUrl($key, now()->addMinutes(5));
                $response = Http::timeout(20)->head($url);
                if ($response->failed()) {
                    throw new \RuntimeException('The signed URL did not answer.');
                }
            });
            $failed = $failed || ! $signed;
        } else {
            $this->line('signed-url: skipped, local disk serves files through the app');
        }

        $delete = $this->step('delete', function () use ($disk, $key) {
            $disk->delete($key);
            if ($disk->exists($key)) {
                throw new \RuntimeException('The canary is still stored.');
            }
        });
        $failed = $failed || ! $delete;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function step(string $name, callable $work): bool
    {
        $started = hrtime(true);
        try {
            $work();
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->line($name.': ok '.$ms.'ms');

            return true;
        } catch (Throwable $e) {
            $this->error($name.': failed');

            return false;
        }
    }
}
