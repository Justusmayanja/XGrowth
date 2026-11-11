<?php

namespace App\Services\TikTok;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\AICore\AiUsageService;
use App\Services\ApiSpend\ApiSpendService;
use App\Support\ElevenLabsCatalog;
use App\Support\SpeechDrivers;
use App\Support\TikTokCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Narration from ElevenLabs. A failed call fails the render.
 * There is no fallback to the tone or to OpenAI.
 *
 * The reserve uses this provider's own per-character rate. The in-app cap is an estimate.
 * The ElevenLabs invoice is the source of truth.
 */
class ElevenLabsSpeechProvider implements SpeechProvider
{
    public function __construct(
        private readonly AiUsageService $usage,
        private readonly ApiSpendService $budget,
        private readonly FfmpegRunner $ffmpeg,
    ) {}

    public function key(): string
    {
        return SpeechDrivers::ELEVENLABS;
    }

    public function isConfigured(string $voice): bool
    {
        return trim((string) config('services.elevenlabs.key')) !== ''
            && $this->voiceId($voice) !== '';
    }

    public function reserveMills(string $text): int
    {
        $text = SceneText::plain($text, 500);

        return ElevenLabsCatalog::reserveMills((string) config('services.elevenlabs.model'), mb_strlen($text));
    }

    public function cacheToken(string $text, string $voice): string
    {
        $text = SceneText::plain($text, 500);

        return 'audio-'.hash('sha256', implode('|', [
            $this->key(),
            (string) config('services.elevenlabs.model'),
            $this->voiceId($voice),
            (string) config('services.elevenlabs.output_format'),
            $text,
        ]));
    }

    public function speak(User $user, string $text, string $voice, string $path): float
    {
        if (is_file($path) && filesize($path) > 0) {
            return 0.0;
        }

        $text = SceneText::plain($text, 500);
        if ($text === '' || ! $this->usage->canUse($user, AiUsageService::ACTION_GENERATION) || ! $this->isConfigured($voice)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }

        $reserve = $this->reserveMills($text);
        if (! $this->budget->canSpend($user->id, $reserve)) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }

        $this->budget->spend($user->id, $reserve);
        $reserved = true;

        try {
            $response = $this->request($text, $this->voiceId($voice));
            $this->accept($response, $path);
            $this->assertReadable($path);
            $this->record($user, $text, $voice);
            $this->budget->release($user->id, $reserve);
            $reserved = false;
            $this->budget->spend($user->id, $reserve);
        } catch (Throwable $e) {
            $this->delete($path);
            if ($reserved) {
                $this->budget->release($user->id, $reserve);
            }
            if ($e instanceof RuntimeException && $e->getMessage() === TikTokCatalog::RENDER_VOICE_UNAVAILABLE) {
                throw $e;
            }

            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }

        return 0.0;
    }

    private function request(string $text, string $voiceId): Response
    {
        $format = (string) config('services.elevenlabs.output_format');
        $url = rtrim((string) config('services.elevenlabs.base_url'), '/').ElevenLabsCatalog::PATH.'/'.rawurlencode($voiceId);

        try {
            return Http::timeout((int) config('services.elevenlabs.timeout', 60))
                ->withHeaders([
                    'xi-api-key' => (string) config('services.elevenlabs.key'),
                    'Accept' => 'audio/mpeg',
                ])
                ->withQueryParameters(['output_format' => $format])
                ->retry(3, 250, function (Throwable $exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && $exception->response !== null
                        && $exception->response->serverError();
                }, throw: false)
                ->post($url, [
                    'text' => $text,
                    'model_id' => (string) config('services.elevenlabs.model'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('ElevenLabs speech failed', ['error' => $e::class]);

            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
    }

    private function accept(Response $response, string $path): void
    {
        if (! $response->successful() || ! $this->audioBody($response)) {
            if (in_array($response->status(), [401, 403], true)) {
                Log::warning('ElevenLabs key rejected');
            } else {
                Log::warning('ElevenLabs speech failed', ['status' => $response->status()]);
            }

            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }

        if (file_put_contents($path, $response->body()) === false) {
            throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
        }
    }

    private function audioBody(Response $response): bool
    {
        if ($response->body() === '') {
            return false;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return $type === '' || str_starts_with($type, 'audio/');
    }

    private function assertReadable(string $path): void
    {
        if (! $this->ffmpeg->ffprobeAvailable()) {
            return;
        }
        if ($this->ffmpeg->duration($path) > 0) {
            return;
        }

        $this->delete($path);

        throw new RuntimeException(TikTokCatalog::RENDER_VOICE_UNAVAILABLE);
    }

    private function record(User $user, string $text, string $voice): void
    {
        $characters = mb_strlen($text);
        AiUsageLog::create([
            'user_id' => $user->id,
            'feature' => ElevenLabsCatalog::FEATURE,
            'model' => (string) config('services.elevenlabs.model'),
            'input_tokens' => $characters,
            'output_tokens' => 0,
            'total_tokens' => $characters,
            'metadata' => [
                'voice' => $voice,
                'provider' => SpeechDrivers::ELEVENLABS,
            ],
        ]);
    }

    private function voiceId(string $voice): string
    {
        $id = config('services.elevenlabs.voices.'.$voice);

        return is_string($id) ? trim($id) : '';
    }

    private function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
