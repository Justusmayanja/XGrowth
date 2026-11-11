<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Services\AICore\AiUsageService;
use App\Services\ApiSpend\ApiSpendService;
use App\Support\SpeechDrivers;
use App\Support\TikTokCatalog;
use RuntimeException;

/**
 * Local stand-in. A sine tone stands in for a voice so a render can be checked without a speech API.
 */
class FakeSpeechProvider implements SpeechProvider
{
    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly AiUsageService $usage,
        private readonly ApiSpendService $budget,
    ) {}

    public function key(): string
    {
        return SpeechDrivers::FAKE;
    }

    public function reserveMills(string $text): int
    {
        $text = SceneText::plain($text, 500);

        return max(10, (int) ceil(mb_strlen($text) * 0.015));
    }

    public function cacheToken(string $text, string $voice): string
    {
        $text = SceneText::plain($text, 500);

        return 'audio-'.hash('sha256', implode('|', [$this->key(), 'fake', $voice, 'aac', $text]));
    }

    public function speak(User $user, string $text, string $voice, string $path): float
    {
        $text = SceneText::plain($text, 500);
        if (! $this->usage->canUse($user, AiUsageService::ACTION_GENERATION)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        $reserve = $this->reserveMills($text);
        if (! $this->budget->canSpend($user->id, $reserve)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        $this->budget->spend($user->id, $reserve);
        $seconds = max(1.2, mb_strlen($text) / 14);
        try {
            $this->ffmpeg->run([
                '-f', 'lavfi',
                '-i', 'sine=frequency=440:duration='.number_format($seconds, 2, '.', ''),
                '-c:a', 'aac',
                '-ar', '48000',
                '-ac', '2',
                $path,
            ], 30);
        } catch (RuntimeException $e) {
            $this->budget->release($user->id, $reserve);
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        $this->budget->release($user->id, $reserve);

        return $seconds;
    }
}
