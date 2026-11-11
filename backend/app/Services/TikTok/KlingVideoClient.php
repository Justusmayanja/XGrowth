<?php

namespace App\Services\TikTok;

use App\Support\TikTokCatalog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Kling 4.0 Flash makes one vertical scene. It does not draw the words.
 */
class KlingVideoClient
{
    public function configured(): bool
    {
        $key = config('services.kling.key');

        return is_string($key) && $key !== '';
    }

    public function film(string $prompt): string
    {
        if (! $this->configured()) {
            throw new RuntimeException(TikTokCatalog::RENDER_SCENE_UNAVAILABLE);
        }

        $created = Http::timeout(40)
            ->withToken($this->key())
            ->acceptJson()
            ->post($this->base().'/text-to-video/kling-4.0-flash', [
                'prompt' => $prompt,
                'settings' => [
                    'duration' => 10,
                    'resolution' => '720p',
                    'aspect_ratio' => '9:16',
                ],
                'options' => [
                    'watermark_info' => ['enabled' => false],
                ],
            ]);

        $id = $created->json('data.id');
        if (! $created->successful() || ! is_string($id) || $id === '') {
            throw new RuntimeException(TikTokCatalog::RENDER_SCENE_FAILED);
        }

        $deadline = time() + 480;
        do {
            $task = Http::timeout(40)
                ->withToken($this->key())
                ->acceptJson()
                ->get($this->base().'/tasks', ['task_ids' => $id]);
            $rows = $task->json('data');
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
            $status = is_array($row) ? (string) ($row['status'] ?? '') : '';
            if ($status === 'succeeded') {
                foreach ($row['outputs'] ?? [] as $output) {
                    if (is_array($output) && ($output['type'] ?? '') === 'video' && is_string($output['url'] ?? null) && $output['url'] !== '') {
                        return $output['url'];
                    }
                }
                throw new RuntimeException(TikTokCatalog::RENDER_SCENE_FAILED);
            }
            if ($status === 'failed' || ! $task->successful()) {
                throw new RuntimeException(TikTokCatalog::RENDER_SCENE_FAILED);
            }
            if (time() >= $deadline) {
                break;
            }
            sleep(3);
        } while (true);

        throw new RuntimeException(TikTokCatalog::RENDER_SCENE_FAILED);
    }

    private function key(): string
    {
        return (string) config('services.kling.key');
    }

    private function base(): string
    {
        return rtrim((string) config('services.kling.base_url'), '/');
    }
}
