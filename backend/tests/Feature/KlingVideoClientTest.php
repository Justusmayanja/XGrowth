<?php

namespace Tests\Feature;

use App\Services\TikTok\KlingVideoClient;
use App\Support\TikTokCatalog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KlingVideoClientTest extends TestCase
{
    public function test_a_missing_key_does_not_call_kling(): void
    {
        config(['services.kling.key' => '']);
        Http::preventStrayRequests();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(TikTokCatalog::RENDER_SCENE_UNAVAILABLE);

        app(KlingVideoClient::class)->film('A shop counter at the end of the day.');
    }

    public function test_flash_is_asked_for_one_vertical_ten_second_scene(): void
    {
        config([
            'services.kling.key' => 'test-key',
            'services.kling.base_url' => 'https://api-singapore.klingai.com',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api-singapore.klingai.com/text-to-video/kling-4.0-flash' => Http::response([
                'code' => 0,
                'data' => ['id' => 'task-1', 'status' => 'submitted'],
            ]),
            'https://api-singapore.klingai.com/tasks*' => Http::response([
                'code' => 0,
                'data' => [[
                    'id' => 'task-1',
                    'status' => 'succeeded',
                    'outputs' => [[
                        'type' => 'video',
                        'url' => 'https://cdn.example/scene.mp4',
                    ]],
                ]],
            ]),
        ]);

        $url = app(KlingVideoClient::class)->film('A shop counter at the end of the day, no text.');

        $this->assertSame('https://cdn.example/scene.mp4', $url);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/text-to-video/kling-4.0-flash')) {
                return false;
            }
            $body = $request->data();

            return ($body['settings']['aspect_ratio'] ?? '') === '9:16'
                && ($body['settings']['duration'] ?? 0) === 10
                && ($body['settings']['resolution'] ?? '') === '720p'
                && ($body['options']['watermark_info']['enabled'] ?? true) === false;
        });
    }
}
