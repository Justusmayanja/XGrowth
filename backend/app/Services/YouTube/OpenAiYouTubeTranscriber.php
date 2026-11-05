<?php

namespace App\Services\YouTube;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Local-file transcription. This class never calls the YouTube Caption API.
 */
class OpenAiYouTubeTranscriber implements YouTubeTranscriber
{
    public function key(): string
    {
        return 'openai';
    }

    public function available(): bool
    {
        return trim((string) config('openai.api_key')) !== '';
    }

    public function transcribe(string $absolutePath): string
    {
        if (! $this->available()) {
            throw new YouTubeRenderException('No local transcription provider is configured.');
        }
        if (! is_file($absolutePath)) {
            throw new YouTubeRenderException('The local file was missing before transcription.');
        }

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new YouTubeRenderException('The local file could not be read for transcription.');
        }

        try {
            $response = Http::withToken((string) config('openai.api_key'))
                ->timeout(120)
                ->attach('file', $handle, basename($absolutePath))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => (string) config('services.youtube.transcription_model', 'whisper-1'),
                    'response_format' => 'srt',
                ]);
        } catch (Throwable) {
            throw new YouTubeRenderException('The local transcript could not be read.');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $response->successful()) {
            throw new YouTubeRenderException('The local transcript could not be read.');
        }

        $text = trim($response->body());
        if ($text === '') {
            throw new YouTubeRenderException('The local transcript was empty.');
        }

        return $text;
    }
}
