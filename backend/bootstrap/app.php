<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\EnsureNotBlocked;
use App\Http\Middleware\LogAccessDenial;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            LogAccessDenial::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'customer' => EnsureCustomer::class,
            'not_blocked' => EnsureNotBlocked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
