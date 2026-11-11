<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\AiUsageLog;
use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\User;
use App\Models\YouTubeVideo;
use App\Providers\AppServiceProvider;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\TikTok\ElevenLabsSpeechProvider;
use App\Services\TikTok\FakeSpeechProvider;
use App\Services\TikTok\OpenAiSpeechProvider;
use App\Services\TikTok\TikTokEngineService;
use App\Services\YouTube\YouTubeFfmpegRenderer;
use App\Services\YouTube\YouTubeRenderException;
use App\Support\SpeechDrivers;
use App\Support\TikTokCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use ReflectionMethod;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class ElevenLabsSpeechTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    private const KEY = 'xi-test-key';

    private const SENTENCE = 'Salt the water first.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        config([
            'openai.api_key' => '',
            'services.elevenlabs.key' => self::KEY,
            'services.elevenlabs.base_url' => 'https://api.elevenlabs.io',
            'services.elevenlabs.model' => 'eleven_multilingual_v2',
            'services.elevenlabs.voices.female' => 'voice-female',
            'services.elevenlabs.voices.male' => 'voice-male',
            'services.elevenlabs.output_format' => 'mp3_44100_128',
            'services.elevenlabs.timeout' => 60,
            'services.tiktok.ffprobe' => 'ffprobe-not-installed',
        ]);
    }

    public function test_a_successful_call_writes_audio_and_keeps_the_model_rate(): void
    {
        $user = User::factory()->create();
        $text = str_repeat('a', 500);
        $path = $this->audioPath();
        Http::fake([
            '*' => Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        app(ElevenLabsSpeechProvider::class)->speak($user, $text, 'female', $path);

        Http::assertSent(function ($request) use ($text) {
            return str_contains($request->url(), '/v1/text-to-speech/voice-female')
                && str_contains($request->url(), 'output_format=mp3_44100_128')
                && $request->hasHeader('xi-api-key', self::KEY)
                && $request['text'] === $text
                && $request['model_id'] === 'eleven_multilingual_v2';
        });
        $this->assertSame('ID3-audio', file_get_contents($path));
        $log = AiUsageLog::query()->where('feature', 'tiktok_tts')->first();
        $this->assertNotNull($log);
        $this->assertSame(500, $log->input_tokens);
        $this->assertSame(500, $log->total_tokens);
        $this->assertSame('elevenlabs', $log->metadata['provider']);
        $this->assertSame(40, (int) Cache::get(app(ApiSpendService::class)->spendKey($user->id), 0));
        @unlink($path);
    }

    public function test_female_and_male_styles_use_their_voice_ids(): void
    {
        $user = User::factory()->create();
        Http::fake([
            '*' => Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $provider = app(ElevenLabsSpeechProvider::class);

        $provider->speak($user, self::SENTENCE, 'female', $this->audioPath());
        $provider->speak($user, self::SENTENCE, 'male', $this->audioPath());

        $urls = [];
        Http::assertSent(function ($request) use (&$urls) {
            $urls[] = $request->url();

            return true;
        });
        $this->assertTrue(collect($urls)->contains(fn (string $url) => str_contains($url, '/voice-female?')));
        $this->assertTrue(collect($urls)->contains(fn (string $url) => str_contains($url, '/voice-male?')));
    }

    public function test_a_missing_key_or_voice_sends_nothing(): void
    {
        $user = User::factory()->create();
        Http::fake();

        config(['services.elevenlabs.key' => '']);
        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $this->audioPath()));

        config(['services.elevenlabs.key' => self::KEY, 'services.elevenlabs.voices.male' => '']);
        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'male', $this->audioPath()));

        Http::assertNothingSent();
    }

    public function test_client_errors_do_not_retry_and_release_the_reserve(): void
    {
        $user = User::factory()->create();
        $spend = app(ApiSpendService::class);

        foreach ([400, 401, 403, 422, 429] as $status) {
            $logs = [];
            Log::listen(function (MessageLogged $event) use (&$logs) {
                $logs[] = $event->message.' '.json_encode($event->context);
            });
            $this->fakeHttp(Http::response('secret-body '.self::SENTENCE, $status, ['Content-Type' => 'application/json']));
            $path = $this->audioPath();
            $before = (int) Cache::get($spend->spendKey($user->id), 0);

            $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $path));

            Http::assertSentCount(1);
            $this->assertSame($before, (int) Cache::get($spend->spendKey($user->id), 0));
            $this->assertSame(0, AiUsageLog::query()->where('feature', 'tiktok_tts')->count());
            $this->assertFileDoesNotExist($path);
            $joined = implode("\n", $logs);
            $this->assertStringNotContainsString(self::KEY, $joined);
            $this->assertStringNotContainsString(self::SENTENCE, $joined);
            $this->assertStringNotContainsString('secret-body', $joined);
            if ($status === 401 || $status === 403) {
                $this->assertTrue(collect($logs)->contains(fn (string $line) => str_contains($line, 'ElevenLabs key rejected')));
            }
        }
    }

    public function test_server_errors_and_connection_errors_retry_then_release_once(): void
    {
        $user = User::factory()->create();
        $spend = app(ApiSpendService::class);
        Http::fake([
            '*' => Http::response('nope', 500),
        ]);
        $before = (int) Cache::get($spend->spendKey($user->id), 0);

        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $this->audioPath()));

        Http::assertSentCount(3);
        $this->assertSame($before, (int) Cache::get($spend->spendKey($user->id), 0));
        $this->assertSame(0, AiUsageLog::query()->count());

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('connection refused');
        });

        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $this->audioPath()));

        $this->assertSame(3, $attempts);
        $this->assertSame($before, (int) Cache::get($spend->spendKey($user->id), 0));
    }

    public function test_an_empty_or_non_audio_body_writes_nothing(): void
    {
        $user = User::factory()->create();
        $spend = app(ApiSpendService::class);

        foreach ([
            Http::response('', 200, ['Content-Type' => 'audio/mpeg']),
            Http::response('not-audio', 200, ['Content-Type' => 'text/plain']),
        ] as $response) {
            $this->fakeHttp($response);
            $path = $this->audioPath();
            $before = (int) Cache::get($spend->spendKey($user->id), 0);

            $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $path));

            $this->assertFileDoesNotExist($path);
            $this->assertSame($before, (int) Cache::get($spend->spendKey($user->id), 0));
        }
    }

    public function test_unreadable_audio_is_deleted_when_ffprobe_is_available(): void
    {
        config(['services.tiktok.ffprobe' => '/bin/true']);
        $user = User::factory()->create();
        $path = $this->audioPath();
        Http::fake([
            '*' => Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $path));

        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertSame(0, (int) Cache::get(app(ApiSpendService::class)->spendKey($user->id), 0));
    }

    public function test_a_second_speak_reuses_the_file_and_a_different_voice_does_not(): void
    {
        $user = User::factory()->create();
        $path = $this->audioPath();
        Http::fake([
            '*' => Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $provider = app(ElevenLabsSpeechProvider::class);

        $provider->speak($user, self::SENTENCE, 'female', $path);
        $provider->speak($user, self::SENTENCE, 'female', $path);

        Http::assertSentCount(1);
        $this->assertNotSame(
            $provider->cacheToken(self::SENTENCE, 'female'),
            $provider->cacheToken(self::SENTENCE, 'male'),
        );
        $this->assertNotSame(
            $provider->cacheToken(self::SENTENCE, 'female'),
            app(FakeSpeechProvider::class)->cacheToken(self::SENTENCE, 'female'),
        );
        @unlink($path);
    }

    public function test_a_full_daily_or_monthly_cap_refuses_before_the_request(): void
    {
        $user = User::factory()->create();
        $spend = app(ApiSpendService::class);
        Http::fake();

        Cache::put($spend->dailySpendKey($user->id), $spend->dailyCapMills($user->id), now()->endOfDay());
        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $this->audioPath()));
        $this->assertSame($spend->dailyCapMills($user->id), (int) Cache::get($spend->dailySpendKey($user->id)));

        Cache::forget($spend->dailySpendKey($user->id));
        Cache::put($spend->spendKey($user->id), $spend->monthlyCapMills($user->id), now()->endOfMonth());
        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak($user, self::SENTENCE, 'female', $this->audioPath()));
        $this->assertSame($spend->monthlyCapMills($user->id), (int) Cache::get($spend->spendKey($user->id)));

        Http::assertNothingSent();
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_drivers_resolve_and_an_unknown_value_fails_without_a_fallback(): void
    {
        $this->assertInstanceOf(FakeSpeechProvider::class, SpeechDrivers::make('fake'));
        $this->assertInstanceOf(OpenAiSpeechProvider::class, SpeechDrivers::make('openai'));
        $this->assertInstanceOf(ElevenLabsSpeechProvider::class, SpeechDrivers::make('elevenlabs'));
        $this->assertSame('fake', config('tiktok_video.tts_driver'));

        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs) {
            $logs[] = $event->message;
        });
        try {
            SpeechDrivers::make('nope');
            $this->fail('An unknown driver was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame(TikTokCatalog::RENDER_VOICE_UNAVAILABLE, $e->getMessage());
        }
        $this->assertContains('Unknown TIKTOK_TTS_DRIVER', $logs);

        $this->mock(FakeSpeechProvider::class, function ($mock) {
            $mock->shouldNotReceive('speak');
        });
        $this->mock(OpenAiSpeechProvider::class, function ($mock) {
            $mock->shouldNotReceive('speak');
        });
        Http::fake(['*' => Http::response('nope', 500)]);
        $this->assertInstanceOf(ElevenLabsSpeechProvider::class, SpeechDrivers::make('elevenlabs'));
        $this->expectVoiceFailure(fn () => app(ElevenLabsSpeechProvider::class)->speak(User::factory()->create(), self::SENTENCE, 'female', $this->audioPath()));
    }

    public function test_youtube_can_opt_into_elevenlabs_without_changing_the_other_drivers(): void
    {
        $user = User::factory()->create();
        $video = YouTubeVideo::query()->create([
            'user_id' => $user->id,
            'mode' => 'series',
            'format' => 'faceless_voiceover',
            'status' => 'waiting',
            'script' => self::SENTENCE,
            'package' => ['render' => ['kind' => 'narration']],
        ]);
        $renderer = app(YouTubeFfmpegRenderer::class);

        config(['services.youtube.tts_driver' => 'elevenlabs']);
        Http::fake([
            '*' => Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $path = $this->narration($renderer, $video);
        $this->assertSame('ID3-audio', file_get_contents($path));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/text-to-speech/voice-female'));

        config(['services.youtube.tts_driver' => 'openai']);
        $this->mock(OpenAiSpeechProvider::class, function ($mock) {
            $mock->shouldReceive('speak')->once()->andReturnUsing(function ($user, $text, $voice, $path) {
                file_put_contents($path, 'openai-audio');

                return 0.0;
            });
        });
        $openai = $this->narration($renderer, $video);
        $this->assertSame('openai-audio', file_get_contents($openai));

        config(['services.youtube.tts_driver' => 'local']);
        try {
            $this->narration($renderer, $video);
            $this->fail('The local tone was made without ffmpeg.');
        } catch (YouTubeRenderException $e) {
            $this->assertSame('The local audio could not be made. Nothing is uploaded.', $e->getMessage());
        }

        config(['services.youtube.tts_driver' => 'elevenlabs']);
        $this->fakeHttp(Http::response('nope', 401));
        try {
            $this->narration($renderer, $video);
            $this->fail('A rejected key still made audio.');
        } catch (YouTubeRenderException $e) {
            $this->assertSame('The voice could not be made. Switch the speech driver to local, then try again. Nothing is uploaded.', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::SENTENCE, $e->getMessage());
        }
    }

    public function test_render_sync_is_local_only(): void
    {
        config(['tiktok.render_sync' => true, 'queue.connections.renders.driver' => 'redis']);
        $this->app['env'] = 'local';
        (new AppServiceProvider($this->app))->configureRenderQueue();
        $this->assertSame('sync', config('queue.connections.renders.driver'));

        config(['tiktok.render_sync' => false, 'queue.connections.renders.driver' => 'redis']);
        (new AppServiceProvider($this->app))->configureRenderQueue();
        $this->assertSame('redis', config('queue.connections.renders.driver'));

        $this->app['env'] = 'testing';
        config(['tiktok.render_sync' => false, 'queue.connections.renders.driver' => 'redis']);
        (new AppServiceProvider($this->app))->configureRenderQueue();
        $this->assertSame('sync', config('queue.connections.renders.driver'));

        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs) {
            if ($event->level === 'warning') {
                $logs[] = $event->message;
            }
        });
        config(['tiktok.render_sync' => true, 'queue.connections.renders.driver' => 'redis']);
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->configureRenderQueue();
        $this->assertSame('redis', config('queue.connections.renders.driver'));
        $this->assertContains('TIKTOK_RENDER_SYNC ignored in production', $logs);
    }

    public function test_a_failing_elevenlabs_render_still_returns_the_clip(): void
    {
        config([
            'tiktok_video.tts_driver' => 'elevenlabs',
            'tiktok.render_sync' => true,
            'queue.connections.renders.driver' => 'sync',
            'queue.connections.renders.after_commit' => false,
        ]);
        Http::fake(['*' => Http::response('nope', 500)]);
        $user = $this->readyUser();

        $result = app(TikTokEngineService::class)->run($user);

        $this->assertSame(TikTokCatalog::CREATED, $result['outcome']);
        $clip = $result['clip']->fresh();
        $this->assertSame(TikTokCatalog::WAITING, $clip->status);
        $this->assertSame(TikTokCatalog::RENDER_FAILED, $clip->package['render']['status']);
        $this->assertSame(TikTokCatalog::RENDER_VOICE_UNAVAILABLE, $clip->package['render']['note']);
    }

    public function test_the_dashboard_does_not_start_a_render_when_sync_is_on(): void
    {
        config(['tiktok.render_sync' => true]);
        $this->app['env'] = 'local';
        (new AppServiceProvider($this->app))->configureRenderQueue();
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk();

        $this->assertSame(TikTokCatalog::RENDER_NONE, $clip->fresh()->package['render']['status']);
    }

    private function fakeHttp(mixed $response): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => $response]);
    }

    private function expectVoiceFailure(callable $call): void
    {
        try {
            $call();
            $this->fail('The voice call was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame(TikTokCatalog::RENDER_VOICE_UNAVAILABLE, $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::SENTENCE, $e->getMessage());
        }
    }

    private function audioPath(): string
    {
        return sys_get_temp_dir().'/elevenlabs-'.uniqid('', true).'.m4a';
    }

    private function narration(YouTubeFfmpegRenderer $renderer, YouTubeVideo $video): string
    {
        $work = sys_get_temp_dir().'/yt-narration-'.uniqid('', true);
        mkdir($work);
        $method = new ReflectionMethod($renderer, 'narrationAudio');

        return $method->invoke($renderer, $video, $work, 8.0);
    }

    private function readyUser(): User
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'tiktok',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'timezone' => 'Africa/Kampala',
            'onboarding_completed' => true,
            'discovery' => ['presence' => 'faceless'],
        ]);
        PlatformSetup::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'goals' => 'for_you',
            'settings' => ['publish' => 'confirm'],
            'completed_at' => now(),
        ]);
        $this->giveTikTokAllowance($user);

        return $user->fresh('accountProfile');
    }
}
