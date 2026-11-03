<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Services\AICore\AiUsageService;
use App\Services\ApiSpend\ApiSpendService;
use App\Support\TikTokCatalog;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;
use Throwable;

class OpenAiSpeechProvider implements SpeechProvider
{
    public function __construct(
        private readonly AiUsageService $usage,
        private readonly ApiSpendService $budget,
    ) {}

    public function key(): string
    {
        return 'openai';
    }

    public function speak(User $user, string $text, string $voice, string $path): float
    {
        $text = SceneText::plain($text, 500);
        if ($text === '') {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        if (! $this->usage->canUse($user, AiUsageService::ACTION_GENERATION)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        $reserve = max(10, (int) ceil(mb_strlen($text) * 0.015));
        if (! $this->budget->canSpend($user->id, $reserve)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
        $this->budget->spend($user->id, $reserve);

        try {
            $voiceId = (string) config('tiktok_video.voices.'.$voice, config('tiktok_video.voices.female'));
            $model = (string) config('tiktok_video.tts_model');
            $response = OpenAI::audio()->speech([
                'model' => $model,
                'voice' => $voiceId,
                'input' => $text,
                'response_format' => 'mp3',
            ]);
            file_put_contents($path, (string) $response);
            $this->usage->record($user, [
                'feature' => 'tiktok_tts',
                'model' => $model,
                'input_tokens' => mb_strlen($text),
                'output_tokens' => 0,
                'total_tokens' => mb_strlen($text),
                'metadata' => ['voice' => $voice],
            ], false);
        } catch (Throwable $e) {
            $this->budget->release($user->id, $reserve);
            if ($e instanceof RuntimeException && $e->getMessage() === TikTokCatalog::RENDER_VOICE_UNAVAILABLE) {
                throw $e;
            }
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }

        $this->budget->release($user->id, $reserve);

        return 0.0;
    }
}
