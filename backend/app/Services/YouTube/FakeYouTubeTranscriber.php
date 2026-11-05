<?php

namespace App\Services\YouTube;

class FakeYouTubeTranscriber implements YouTubeTranscriber
{
    public function key(): string
    {
        return 'fake';
    }

    public function available(): bool
    {
        return trim((string) config('services.youtube.fake_transcript')) !== '';
    }

    public function transcribe(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            throw new YouTubeRenderException('The local file was missing before transcription.');
        }

        $text = trim((string) config('services.youtube.fake_transcript'));
        if ($text === '') {
            throw new YouTubeRenderException('The local transcript was empty.');
        }

        return $text;
    }
}
