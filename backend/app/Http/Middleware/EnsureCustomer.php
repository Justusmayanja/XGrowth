<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin) {
            return response()->json([
                'message' => 'Platform operators use the admin console, not the customer app.',
            ], 403);
        }

        return $next($request);
    }
}
