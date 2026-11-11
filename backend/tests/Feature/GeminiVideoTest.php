<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\TikTok\GeminiVideoClient;
use App\Services\TikTok\TikTokRenderService;
use App\Support\GeminiCatalog;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use ReflectionMethod;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class GeminiVideoTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    private const KEY = 'gemini-test-key';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => self::KEY,
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com',
            'services.gemini.video_model' => GeminiCatalog::MODEL,
            'tiktok_video.picture' => 'motion',
        ]);
    }

    public function test_prompt_keeps_letters_out_of_the_picture(): void
    {
        $prompt = app(GeminiVideoClient::class)->prompt([
            'hook' => 'ignored when a scene exists',
            'scenes' => [
                ['narration' => 'Salt the water first.'],
            ],
        ]);

        $this->assertStringContainsString('No words, letters, logos, or captions', $prompt);
        $this->assertStringContainsString('Salt the water first.', $prompt);
        $this->assertStringNotContainsString('ignored when a scene exists', $prompt);
    }

    public function test_download_requests_a_vertical_clip_and_saves_the_file(): void
    {
        $user = $this->readyUser();
        $uri = 'https://generativelanguage.googleapis.com/v1beta/files/clip:download?alt=media';
        $this->fakeHttp(Http::sequence()
            ->push([
                'id' => 'job-1',
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'video',
                        'uri' => $uri,
                    ]],
                ]],
            ], 200)
            ->push('mp4-bytes', 200, ['Content-Type' => 'video/mp4']));

        $path = storage_path('app/gemini-test.mp4');
        @unlink($path);
        app(GeminiVideoClient::class)->download($user, [
            'scenes' => [['narration' => 'Salt the water first.']],
        ], $path);

        $this->assertSame('mp4-bytes', file_get_contents($path));
        @unlink($path);
        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/interactions', $recorded[0][0]->url());
        $this->assertSame(self::KEY, $recorded[0][0]->header('x-goog-api-key')[0]);
        $body = $recorded[0][0]->data();
        $this->assertSame(GeminiCatalog::MODEL, $body['model']);
        $this->assertSame('9:16', $body['response_format']['aspect_ratio']);
        $this->assertSame('15s', $body['response_format']['duration']);
        $this->assertSame('uri', $body['response_format']['delivery']);
        $this->assertTrue($body['background']);
        $this->assertSame($uri, $recorded[1][0]->url());
        $this->assertSame(1, AiUsageLog::query()->where('feature', GeminiCatalog::FEATURE)->count());
        $this->assertSame(GeminiCatalog::RESERVE_MILLS, (int) Cache::get(app(ApiSpendService::class)->spendKey($user->id), 0));
    }

    public function test_missing_key_sends_nothing(): void
    {
        config(['services.gemini.key' => '']);
        $this->fakeHttp(Http::response('nope', 200));
        $user = $this->readyUser();

        try {
            app(GeminiVideoClient::class)->download($user, [
                'scenes' => [['narration' => 'Salt the water first.']],
            ], storage_path('app/gemini-missing.mp4'));
            $this->fail('A missing key should fail the render.');
        } catch (\RuntimeException $e) {
            $this->assertSame(GeminiCatalog::FAILED, $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_rejected_key_releases_the_reserve_and_hides_the_secret(): void
    {
        $user = $this->readyUser();
        $this->fakeHttp(Http::response('{"error":"secret '.self::KEY.'"}', 401));
        $messages = [];
        Log::listen(function (MessageLogged $event) use (&$messages) {
            $messages[] = $event->message.' '.json_encode($event->context);
        });
        $spend = app(ApiSpendService::class);

        try {
            app(GeminiVideoClient::class)->download($user, [
                'scenes' => [['narration' => 'Salt the water first.']],
            ], storage_path('app/gemini-rejected.mp4'));
            $this->fail('A rejected key should fail the render.');
        } catch (\RuntimeException $e) {
            $this->assertSame(GeminiCatalog::FAILED, $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString('Salt the water', $e->getMessage());
        }

        $this->assertSame(0, (int) Cache::get($spend->spendKey($user->id), 0));
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_contains($message, 'Gemini key rejected')));
        $joined = implode("\n", $messages);
        $this->assertStringNotContainsString(self::KEY, $joined);
        $this->assertStringNotContainsString('Salt the water', $joined);
    }

    public function test_a_failed_interaction_logs_the_code_only(): void
    {
        $user = $this->readyUser();
        $this->fakeHttp(Http::response([
            'id' => 'job-fail',
            'status' => 'failed',
            'errors' => [[
                'code' => 'RESOURCE_EXHAUSTED',
                'message' => 'Salt the water first. '.self::KEY,
            ]],
        ], 200));
        $messages = [];
        Log::listen(function (MessageLogged $event) use (&$messages) {
            $messages[] = $event->message.' '.json_encode($event->context);
        });

        try {
            app(GeminiVideoClient::class)->download($user, [
                'scenes' => [['narration' => 'Salt the water first.']],
            ], storage_path('app/gemini-quota.mp4'));
            $this->fail('A failed interaction should fail the render.');
        } catch (\RuntimeException $e) {
            $this->assertSame(GeminiCatalog::QUOTA, $e->getMessage());
        }

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('RESOURCE_EXHAUSTED', $joined);
        $this->assertStringNotContainsString(self::KEY, $joined);
        $this->assertStringNotContainsString('Salt the water', $joined);
        $this->assertSame(0, (int) Cache::get(app(ApiSpendService::class)->spendKey($user->id), 0));
    }

    public function test_a_foreign_download_uri_is_refused(): void
    {
        $user = $this->readyUser();
        $this->fakeHttp(Http::response([
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'video',
                    'uri' => 'https://evil.example/clip.mp4',
                ]],
            ]],
        ], 200));
        $path = storage_path('app/gemini-foreign.mp4');
        @unlink($path);

        try {
            app(GeminiVideoClient::class)->download($user, [
                'scenes' => [['narration' => 'Salt the water first.']],
            ], $path);
            $this->fail('A foreign file host should fail the render.');
        } catch (\RuntimeException $e) {
            $this->assertSame(GeminiCatalog::FAILED, $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
        $this->assertCount(1, Http::recorded());
        $this->assertSame(0, (int) Cache::get(app(ApiSpendService::class)->spendKey($user->id), 0));
    }

    public function test_in_progress_is_polled_until_the_file_is_ready(): void
    {
        Sleep::fake();
        $user = $this->readyUser();
        $uri = 'https://generativelanguage.googleapis.com/v1beta/files/later:download?alt=media';
        $this->fakeHttp(Http::sequence()
            ->push(['id' => 'job-2', 'status' => 'in_progress'], 200)
            ->push([
                'id' => 'job-2',
                'status' => 'completed',
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [['type' => 'video', 'uri' => $uri]],
                ]],
            ], 200)
            ->push('later-bytes', 200, ['Content-Type' => 'video/mp4']));

        $path = storage_path('app/gemini-poll.mp4');
        @unlink($path);
        app(GeminiVideoClient::class)->download($user, [
            'scenes' => [['narration' => 'Salt the water first.']],
        ], $path);

        $this->assertSame('later-bytes', file_get_contents($path));
        @unlink($path);
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/interactions/job-2',
            Http::recorded()[1][0]->url(),
        );
    }

    public function test_unknown_picture_provider_does_not_fall_back_to_motion(): void
    {
        config(['tiktok_video.picture' => 'cartoon']);
        $this->fakeHttp(Http::response('nope', 500));
        $messages = [];
        Log::listen(function (MessageLogged $event) use (&$messages) {
            $messages[] = $event->message;
        });
        $method = new ReflectionMethod(TikTokRenderService::class, 'picture');
        $user = $this->readyUser();

        try {
            $method->invoke(app(TikTokRenderService::class), $user, sys_get_temp_dir(), []);
            $this->fail('An unknown picture provider should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame(GeminiCatalog::FAILED, $e->getMessage());
        }

        $this->assertTrue(collect($messages)->contains(fn ($message) => str_contains($message, 'Unknown TIKTOK_VIDEO_PROVIDER')));
        Http::assertNothingSent();
    }

    private function readyUser(): User
    {
        $user = User::factory()->create();
        $this->giveTikTokAllowance($user);

        return $user;
    }

    private function fakeHttp(ResponseSequence|callable|PromiseInterface|Response $response): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => $response]);
    }
}
