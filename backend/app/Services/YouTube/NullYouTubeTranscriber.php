<?php

namespace App\Services\YouTube;

class NullYouTubeTranscriber implements YouTubeTranscriber
{
    public function key(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function transcribe(string $absolutePath): string
    {
        throw new YouTubeRenderException('No local transcription provider is configured.');
    }
}
