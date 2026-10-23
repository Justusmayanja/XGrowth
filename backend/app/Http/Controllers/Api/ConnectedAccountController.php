<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XApi\XApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectedAccountController extends Controller
{
    public function __construct(
        private readonly XApiService $x,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->connectedAccounts()
            ->select(['id', 'platform', 'username', 'is_active', 'created_at'])
            ->get();

        return response()->json(['accounts' => $accounts]);
    }

    public function connectStatus(Request $request): JsonResponse
    {
        $account = $request->user()
            ->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if ($account) {
            return response()->json([
                'available' => true,
                'connected' => true,
                'username' => $account->username,
                'authorize_url' => null,
                'message' => "@{$account->username} is connected.",
            ]);
        }

        if (! $this->x->configured()) {
            return response()->json([
                'available' => false,
                'connected' => false,
                'authorize_url' => null,
                'message' => 'X sign-in is not turned on for this server yet.',
            ]);
        }

        $next = $this->safeNext($request->query('next'));

        return response()->json([
            'available' => true,
            'connected' => false,
            'authorize_url' => $this->x->authorizationUrl([
                'provider' => 'x',
                'purpose' => 'connect',
                'user_id' => $request->user()->id,
                'next' => $next,
            ]),
            'message' => 'Connect X to publish and read live totals.',
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $request->user()
            ->connectedAccounts()
            ->where('platform', 'x')
            ->delete();

        return response()->json([
            'available' => $this->x->configured(),
            'connected' => false,
            'message' => 'X is disconnected.',
        ]);
    }

    private function safeNext(mixed $next): string
    {
        return in_array($next, ['/settings', '/onboarding'], true) ? $next : '/settings';
    }
}
