<?php

namespace App\Filesystem;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\WhitespacePathNormalizer;

/**
 * A namespace on the shared media disk. Paths are confined to the prefix,
 * and a ".." segment is rejected before it can leave that prefix.
 */
class StrictScopedDisk
{
    public function __invoke(Application $app, array $config): FilesystemAdapter
    {
        $parentName = (string) ($config['disk'] ?? '');
        $prefix = trim((string) ($config['prefix'] ?? ''), '/');
        if ($parentName === '' || $prefix === '') {
            throw new InvalidArgumentException('A prefixed media disk needs a parent disk and a prefix.');
        }

        $parent = $app['filesystem']->disk($parentName);
        $adapter = new PathPrefixedAdapter($parent->getAdapter(), $prefix);
        $flysystem = new Filesystem($adapter, [
            'visibility' => $config['visibility'] ?? 'private',
            'directory_visibility' => $config['directory_visibility'] ?? 'private',
        ], new WhitespacePathNormalizer(false));

        $parentConfig = config('filesystems.disks.'.$parentName);
        if (is_array($parentConfig) && ($parentConfig['driver'] ?? '') === 'local' && isset($parentConfig['root'])) {
            $config['root'] = $parentConfig['root'];
        }

        return new FilesystemAdapter($flysystem, $adapter, $config);
    }
}
