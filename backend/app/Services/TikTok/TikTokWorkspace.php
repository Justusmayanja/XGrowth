<?php

namespace App\Services\TikTok;

use App\Models\TikTokClipVideo;
use App\Services\Storage\MediaStore;
use App\Support\TikTokContentPosting;
use RuntimeException;

/**
 * Scratch space for ffmpeg, probes, and downloads. These files never go to object storage.
 */
class TikTokWorkspace
{
    private ?string $directory = null;

    public function begin(int $clipId): string
    {
        $this->directory = storage_path('app/tiktok-work/'.$clipId.'-'.bin2hex(random_bytes(8)));
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0755, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Could not open a work directory.');
        }

        return $this->directory;
    }

    public function directory(): string
    {
        if ($this->directory === null) {
            throw new RuntimeException('Could not open a work directory.');
        }

        return $this->directory;
    }

    /**
     * Copy a stored object into the work directory. The caller deletes the directory in finally.
     */
    public function localCopy(TikTokClipVideo $video): string
    {
        if ($this->directory === null) {
            $this->begin((int) $video->clip_id);
        }

        $disk = $video->disk ?: TikTokContentPosting::DISK;
        $key = (string) $video->path;
        $object = app(MediaStore::class)->materialize($disk, $key);
        $dest = $this->directory.'/source.mp4';
        if ($object->temporary) {
            rename($object->path, $dest);
        } else {
            copy($object->path, $dest);
        }

        return $dest;
    }

    public function cleanup(): void
    {
        if ($this->directory !== null && is_dir($this->directory)) {
            $this->deleteTree($this->directory);
        }
        $this->directory = null;
    }

    /**
     * Remove work directories left behind by a crashed job.
     */
    public function sweep(int $hours = 6): int
    {
        $root = storage_path('app/tiktok-work');
        if (! is_dir($root)) {
            return 0;
        }

        $cutoff = time() - ($hours * 3600);
        $removed = 0;
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root.'/'.$name;
            if (! is_dir($path)) {
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime <= $cutoff) {
                $this->deleteTree($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function deleteTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->deleteTree($path.'/'.$name);
        }
        rmdir($path);
    }
}
