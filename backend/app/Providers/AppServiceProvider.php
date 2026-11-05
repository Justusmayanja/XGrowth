<?php

namespace App\Providers;

use App\Filesystem\StrictScopedDisk;
use App\Services\YouTube\FakeYouTubeTranscriber;
use App\Services\YouTube\NullYouTubeTranscriber;
use App\Services\YouTube\OpenAiYouTubeTranscriber;
use App\Services\YouTube\YouTubeTranscriber;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(YouTubeTranscriber::class, function ($app) {
            return match ((string) config('services.youtube.transcription_driver', 'none')) {
                'openai' => $app->make(OpenAiYouTubeTranscriber::class),
                'fake' => $app->make(FakeYouTubeTranscriber::class),
                default => $app->make(NullYouTubeTranscriber::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Storage::extend('strict_scoped', fn ($app, array $config) => (new StrictScopedDisk)($app, $config));

        if ($this->app->environment('testing')) {
            config(['queue.connections.renders.driver' => 'sync']);
        }
    }
}
