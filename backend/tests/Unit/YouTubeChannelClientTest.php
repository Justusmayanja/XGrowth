<?php

namespace Tests\Unit;

use App\Exceptions\YouTubeOAuthException;
use App\Services\YouTube\YouTubeChannelClient;
use App\Support\YouTubeOAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeChannelClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.youtube.client_id' => 'test-client',
            'services.youtube.client_secret' => 'test-secret',
            'services.youtube.redirect' => 'http://127.0.0.1:8000/api/auth/youtube/callback',
        ]);
        Http::preventStrayRequests();
    }

    public function test_fake_http_reads_channel_and_last_twenty_eight_day_metrics(): void
    {
        Http::fake([
            YouTubeOAuth::CHANNELS_URL.'*' => Http::response([
                'items' => [[
                    'id' => 'UC-123',
                    'snippet' => ['title' => 'Kitchen Channel'],
                    'statistics' => ['subscriberCount' => '321', 'viewCount' => '9876'],
                ]],
            ]),
            YouTubeOAuth::ANALYTICS_URL.'*' => Http::response(['rows' => [[1200, 765]]]),
        ]);
        $client = app(YouTubeChannelClient::class);

        $this->assertSame([
            'id' => 'UC-123',
            'title' => 'Kitchen Channel',
            'subscribers' => 321,
            'views' => 9876,
        ], $client->channel('access-token'));
        $this->assertSame([
            'watch_minutes' => 1200.0,
            'views' => 765,
        ], $client->analytics('access-token', Carbon::parse('2026-11-05')));

        Http::assertSent(fn ($request) => str_starts_with($request->url(), YouTubeOAuth::ANALYTICS_URL)
            && $request['startDate'] === '2026-10-09'
            && $request['endDate'] === '2026-11-05'
            && $request['metrics'] === 'estimatedMinutesWatched,views');
    }

    public function test_fake_http_failure_returns_a_typed_reason(): void
    {
        Http::fake([
            YouTubeOAuth::CHANNELS_URL.'*' => Http::response(['error' => ['message' => 'forbidden']], 403),
        ]);

        try {
            app(YouTubeChannelClient::class)->channel('access-token');
            $this->fail('A failed channel read must not look successful.');
        } catch (YouTubeOAuthException $e) {
            $this->assertSame('channel_unavailable', $e->reason);
        }
    }
}
