<?php

namespace App\Services\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One private object store for the whole app. Features pick a namespace,
 * which is a prefix on the shared media disk, not a second bucket.
 */
class MediaStore
{
    public const TIKTOK = 'tiktok';

    public const YOUTUBE = 'youtube';

    public function disk(?string $namespace = self::TIKTOK): Filesystem
    {
        return Storage::disk($this->diskName($namespace));
    }

    public function diskName(?string $namespace): string
    {
        if ($namespace === null || $namespace === '') {
            return 'media';
        }

        $name = config('media.namespaces.'.$namespace);
        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Unknown media namespace.');
        }

        return $name;
    }

    /**
     * @param  resource  $stream
     */
    public function putStream(string $key, $stream, ?string $namespace = self::TIKTOK): void
    {
        $this->disk($namespace)->writeStream($key, $stream);
    }

    /**
     * @return resource
     */
    public function readStream(string $key, ?string $namespace = self::TIKTOK)
    {
        return $this->disk($namespace)->readStream($key);
    }

    public function delete(string $key, ?string $namespace = self::TIKTOK): void
    {
        $disk = $this->disk($namespace);
        if ($disk->exists($key)) {
            $disk->delete($key);
        }
    }

    public function deletePrefix(string $prefix, ?string $namespace = self::TIKTOK): void
    {
        $this->disk($namespace)->deleteDirectory(trim($prefix, '/'));
    }

    /**
     * List one prefix. Callers pass a user id, never the bucket root.
     *
     * @return list<string>
     */
    public function listPrefix(string $prefix, ?string $namespace = self::TIKTOK): array
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return [];
        }

        return $this->disk($namespace)->allFiles($prefix);
    }

    public function supportsTemporaryUrls(?string $namespace = self::TIKTOK): bool
    {
        return ! $this->isLocal($this->diskName($namespace));
    }

    public function temporaryUrl(string $key, \DateTimeInterface $expires, ?string $namespace = self::TIKTOK): string
    {
        return $this->disk($namespace)->temporaryUrl($key, $expires);
    }

    /**
     * A local path for a stored object. Remote objects are copied and marked temporary.
     */
    public function materialize(string $disk, string $key): LocalObject
    {
        $filesystem = Storage::disk($disk);
        if ($this->isLocal($disk)) {
            return new LocalObject($filesystem->path($key), false);
        }

        $directory = storage_path('app/tiktok-work');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not open a work directory.');
        }

        $path = $directory.'/copy-'.bin2hex(random_bytes(8));
        $in = $filesystem->readStream($key);
        $out = fopen($path, 'wb');
        if ($out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            throw new RuntimeException('Could not open a work directory.');
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }
        }

        return new LocalObject($path, true);
    }

    public function isLocal(string $disk): bool
    {
        $driver = config('filesystems.disks.'.$disk.'.driver');
        if ($driver === 'scoped' || $driver === 'strict_scoped') {
            $parent = (string) config('filesystems.disks.'.$disk.'.disk');
            $driver = config('filesystems.disks.'.$parent.'.driver');
        }

        return $driver === 'local' || $driver === null;
    }
}
