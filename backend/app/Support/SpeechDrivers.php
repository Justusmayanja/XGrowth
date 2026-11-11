<?php

namespace App\Support;

use App\Services\TikTok\ElevenLabsSpeechProvider;
use App\Services\TikTok\FakeSpeechProvider;
use App\Services\TikTok\OpenAiSpeechProvider;
use App\Services\TikTok\SpeechProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class SpeechDrivers
{
    public const FAKE = 'fake';

    public const OPENAI = 'openai';

    public const ELEVENLABS = 'elevenlabs';

    public static function make(string $driver): SpeechProvider
    {
        return match ($driver) {
            self::FAKE => app(FakeSpeechProvider::class),
            self::OPENAI => app(OpenAiSpeechProvider::class),
            self::ELEVENLABS => app(ElevenLabsSpeechProvider::class),
            default => self::unknown(),
        };
    }

    private static function unknown(): never
    {
        Log::warning('Unknown TIKTOK_TTS_DRIVER');

        throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
    }
}
