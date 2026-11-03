<?php

namespace App\Providers;

use App\Filesystem\StrictScopedDisk;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
