<?php

namespace App\Services\YouTube;

interface YouTubeTranscriber
{
    public function key(): string;

    public function available(): bool;

    public function transcribe(string $absolutePath): string;
}
