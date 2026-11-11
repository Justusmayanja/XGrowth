<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Services\AICore\AiUsageService;
use App\Services\ApiSpend\ApiSpendService;
use App\Support\TikTokCatalog;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The voice on every published TikTok. One voice for the brand, read from the script.
 */
class ElevenLabsSpeechProvider implements SpeechProvider
{
    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly AiUsageService $usage,
        private readonly ApiSpendService $budget,
    ) {}

    public function key(): string
    {
        return 'elevenlabs';
    }

    public function speak(User $user, string $text, string $voice, string $path): float
    {
        $text = SceneText::plain($text, 500);
        $apiKey = config('services.elevenlabs.key');
        if ($text === '' || ! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        if (! $this->usage->canUse($user, AiUsageService::ACTION_GENERATION)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        $reserve = max(10, (int) ceil(mb_strlen($text) * 0.08));
        if (! $this->budget->canSpend($user->id, $reserve)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        $this->budget->spend($user->id, $reserve);

        $voiceId = (string) config('services.elevenlabs.voices.'.$voice, config('services.elevenlabs.voices.female'));
        $model = (string) config('services.elevenlabs.model');
        $mp3 = $path.'.mp3';
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'xi-api-key' => $apiKey,
                    'Accept' => 'audio/mpeg',
                ])
                ->post('https://api.elevenlabs.io/v1/text-to-speech/'.$voiceId, [
                    'text' => $text,
                    'model_id' => $model,
                ]);
            if (! $response->successful() || strlen($response->body()) < 64) {
                throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
            }
            file_put_contents($mp3, $response->body());
            $this->ffmpeg->run([
                '-i', $mp3,
                '-c:a', 'aac',
                '-ar', '48000',
                '-ac', '2',
                $path,
            ], 30);
            $this->usage->record($user, [
                'feature' => 'tiktok_tts',
                'model' => $model,
                'input_tokens' => mb_strlen($text),
                'output_tokens' => 0,
                'total_tokens' => mb_strlen($text),
                'metadata' => ['voice' => $voice, 'provider' => 'elevenlabs'],
            ], false);
        } catch (Throwable $e) {
            $this->budget->release($user->id, $reserve);
            if (is_file($mp3)) {
                @unlink($mp3);
            }
            if ($e instanceof RuntimeException && $e->getMessage() === TikTokCatalog::RENDER_VOICE_UNAVAILABLE) {
                throw $e;
            }
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        @unlink($mp3);
        $this->budget->release($user->id, $reserve);

        return 0.0;
    }
}
