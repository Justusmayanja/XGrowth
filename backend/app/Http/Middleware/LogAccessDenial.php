<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogAccessDenial
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! in_array($response->getStatusCode(), [401, 403, 429], true)) {
            return;
        }

        Log::channel('abuse')->info('access_denied', [
            'status' => $response->getStatusCode(),
            'method' => $request->getMethod(),
            'path' => '/'.$request->path(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);
    }
}
