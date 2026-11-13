<?php

namespace App\Services\TikTok;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\ApiSpend\ApiSpendService;
use App\Support\GeminiCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * One Gemini Omni clip. Native audio comes with the picture, so this path
 * does not call a speech provider. A missing key or a failed call fails the
 * render. There is no fallback to motion graphics.
 */
class GeminiVideoClient
{
    public function __construct(private readonly ApiSpendService $budget) {}

    /**
     * @param  array<string, mixed>  $spec
     */
    public function download(User $user, array $spec, string $path): void
    {
        $prompt = $this->prompt($spec);
        $key = trim((string) config('services.gemini.key'));
        if ($key === '' || $prompt === '') {
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        $reserve = GeminiCatalog::RESERVE_MILLS;
        if (! $this->budget->canSpend($user->id, $reserve)) {
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        $this->budget->spend($user->id, $reserve);
        $reserved = true;
        try {
            $body = $this->create($key, $prompt);
            $video = $this->awaitVideo($key, $body);
            $this->write($key, $video, $path);
            $this->record($user);
            $this->budget->release($user->id, $reserve);
            $reserved = false;
            $this->budget->spend($user->id, $reserve);
        } catch (Throwable $e) {
            $this->delete($path);
            if ($reserved) {
                $this->budget->release($user->id, $reserve);
            }
            if ($e instanceof RuntimeException && in_array($e->getMessage(), [GeminiCatalog::FAILED, GeminiCatalog::QUOTA], true)) {
                throw $e;
            }
            throw new RuntimeException(GeminiCatalog::FAILED);
        }
    }

    /**
     * Visual direction plus the spoken lines. The picture is told to carry no letters.
     *
     * @param  array<string, mixed>  $spec
     */
    public function prompt(array $spec): string
    {
        $spoken = [];
        $scenes = is_array($spec['scenes'] ?? null) ? $spec['scenes'] : [];
        foreach ($scenes as $scene) {
            if (! is_array($scene)) {
                continue;
            }
            $line = trim((string) ($scene['narration'] ?? ''));
            if ($line !== '') {
                $spoken[] = $line;
            }
        }
        $text = trim(implode(' ', $spoken));
        if ($text === '') {
            $text = trim((string) ($spec['hook'] ?? ''));
        }
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > 800) {
            $text = mb_substr($text, 0, 799).'…';
        }

        return 'One continuous vertical scene for a short social clip. No words, letters, logos, or captions in the picture. Show the idea visually. The soundtrack speaks in a clear voice: '.$text;
    }

    /**
     * @return array<string, mixed>
     */
    private function create(string $key, string $prompt): array
    {
        try {
            $response = Http::withHeaders($this->headers($key))
                ->timeout(120)
                ->retry(2, 500, function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && $exception->response->status() >= 500;
                }, throw: false)
                ->post($this->url(GeminiCatalog::PATH), [
                    'model' => (string) config('services.gemini.video_model', GeminiCatalog::MODEL),
                    'input' => $prompt,
                    'background' => true,
                    'response_format' => [
                        'type' => 'video',
                        'aspect_ratio' => GeminiCatalog::ASPECT,
                        'duration' => GeminiCatalog::DURATION,
                        'resolution' => GeminiCatalog::RESOLUTION,
                        'delivery' => 'uri',
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Gemini video failed', ['error' => $e::class]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        return $this->jsonBody($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{uri?: string, data?: string}
     */
    private function awaitVideo(string $key, array $body): array
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $video = $this->videoPart($body);
            if ($video !== null) {
                return $video;
            }
            $status = strtolower((string) ($body['status'] ?? ''));
            $id = $body['id'] ?? null;
            if (! is_string($id) || $id === '' || ! in_array($status, ['in_progress', 'processing', 'running'], true)) {
                $this->logStatus($body);
                throw new RuntimeException($this->plainFailure($body));
            }
            Sleep::sleep(5);
            $body = $this->fetch($key, $id);
        }

        throw new RuntimeException(GeminiCatalog::FAILED);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $key, string $id): array
    {
        try {
            $response = Http::withHeaders($this->headers($key))
                ->timeout(60)
                ->get($this->url(GeminiCatalog::PATH.'/'.rawurlencode($id)));
        } catch (ConnectionException $e) {
            Log::warning('Gemini video failed', ['error' => $e::class]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        return $this->jsonBody($response);
    }

    /**
     * @param  array{uri?: string, data?: string}  $video
     */
    private function write(string $key, array $video, string $path): void
    {
        if (isset($video['data'])) {
            $bytes = base64_decode($video['data'], true);
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 100_000_000) {
                throw new RuntimeException(GeminiCatalog::FAILED);
            }
            if (file_put_contents($path, $bytes) === false) {
                throw new RuntimeException(GeminiCatalog::FAILED);
            }

            return;
        }

        $uri = (string) ($video['uri'] ?? '');
        if (! $this->allowedUri($uri)) {
            Log::warning('Gemini video failed', ['status' => 0]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        try {
            $response = Http::withHeaders($this->headers($key))
                ->timeout(120)
                ->get($uri);
        } catch (ConnectionException $e) {
            Log::warning('Gemini video failed', ['error' => $e::class]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        if (! $response->successful() || $response->body() === '' || strlen($response->body()) > 100_000_000) {
            Log::warning('Gemini video failed', ['status' => $response->status()]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($type !== '' && ! str_starts_with($type, 'video/')) {
            Log::warning('Gemini video failed', ['status' => $response->status()]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }
        if (file_put_contents($path, $response->body()) === false) {
            throw new RuntimeException(GeminiCatalog::FAILED);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{uri?: string, data?: string}|null
     */
    private function videoPart(array $body): ?array
    {
        $steps = is_array($body['steps'] ?? null) ? $body['steps'] : [];
        foreach (array_reverse($steps) as $step) {
            if (! is_array($step) || ($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            $content = is_array($step['content'] ?? null) ? $step['content'] : [];
            foreach (array_reverse($content) as $part) {
                if (! is_array($part) || ($part['type'] ?? '') !== 'video') {
                    continue;
                }
                if (is_string($part['uri'] ?? null) && $part['uri'] !== '') {
                    return ['uri' => $part['uri']];
                }
                if (is_string($part['data'] ?? null) && $part['data'] !== '') {
                    return ['data' => $part['data']];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Response $response): array
    {
        if ($response->status() === 429) {
            Log::warning('Gemini video failed', ['status' => 429, 'code' => 'RESOURCE_EXHAUSTED']);
            throw new RuntimeException(GeminiCatalog::QUOTA);
        }
        if (in_array($response->status(), [401, 403], true)) {
            Log::warning('Gemini key rejected');
            throw new RuntimeException(GeminiCatalog::FAILED);
        }
        if (! $response->successful()) {
            Log::warning('Gemini video failed', ['status' => $response->status()]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }
        $json = $response->json();
        if (! is_array($json)) {
            Log::warning('Gemini video failed', ['status' => $response->status()]);
            throw new RuntimeException(GeminiCatalog::FAILED);
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function logStatus(array $body): void
    {
        $status = $body['status'] ?? null;
        $code = '';
        $errors = $body['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0]) && is_string($errors[0]['code'] ?? null)) {
            $code = $errors[0]['code'];
        }
        Log::warning('Gemini video failed', [
            'status' => is_scalar($status) ? (string) $status : 0,
            'code' => $code,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function plainFailure(array $body): string
    {
        $errors = $body['errors'] ?? null;
        $code = is_array($errors) && isset($errors[0]) && is_array($errors[0]) && is_string($errors[0]['code'] ?? null)
            ? $errors[0]['code']
            : '';

        return $code === 'RESOURCE_EXHAUSTED' ? GeminiCatalog::QUOTA : GeminiCatalog::FAILED;
    }

    private function allowedUri(string $uri): bool
    {
        $baseHost = parse_url((string) config('services.gemini.base_url'), PHP_URL_HOST);
        $host = parse_url($uri, PHP_URL_HOST);
        $scheme = parse_url($uri, PHP_URL_SCHEME);

        return is_string($baseHost) && $baseHost !== ''
            && $host === $baseHost
            && $scheme === 'https';
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $key): array
    {
        return [
            'x-goog-api-key' => $key,
            'Accept' => 'application/json',
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.gemini.base_url'), '/').$path;
    }

    private function record(User $user): void
    {
        AiUsageLog::query()->create([
            'user_id' => $user->id,
            'feature' => GeminiCatalog::FEATURE,
            'model' => (string) config('services.gemini.video_model', GeminiCatalog::MODEL),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'metadata' => ['provider' => 'gemini'],
        ]);
    }

    private function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
