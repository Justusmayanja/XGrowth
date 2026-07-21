<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_blocked && ! $user->is_admin) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your account has been suspended. Contact support if you believe this is an error.',
                'blocked' => true,
            ], 403);
        }

        return $next($request);
    }
}
