<?php

namespace App\Services\Storage;

/**
 * A file on the local machine. Temporary copies are removed by cleanup().
 */
final class LocalObject
{
    public function __construct(
        public readonly string $path,
        public readonly bool $temporary,
    ) {}

    public function cleanup(): void
    {
        if ($this->temporary && is_file($this->path)) {
            unlink($this->path);
        }
    }
}
