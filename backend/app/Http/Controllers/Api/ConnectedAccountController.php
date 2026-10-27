<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TikTokOAuthException;
use App\Http\Controllers\Controller;
use App\Services\TikTok\TikTokOAuthService;
use App\Services\XApi\XApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConnectedAccountController extends Controller
{
    public function __construct(
        private readonly XApiService $x,
        private readonly TikTokOAuthService $tiktok,
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

    public function tiktokConnect(Request $request): JsonResponse
    {
        try {
            $url = $this->tiktok->authorizationUrl($request->user());
        } catch (TikTokOAuthException $e) {
            if ($e->reason === 'not_configured') {
                return response()->json([
                    'error' => [
                        'code' => 'tiktok_not_configured',
                        'message' => "TikTok linking isn't available yet. It's waiting on TikTok's approval.",
                    ],
                ], 503);
            }

            throw $e;
        }

        return response()->json([
            'data' => ['authorize_url' => $url],
        ]);
    }

    public function tiktokCallback(Request $request): RedirectResponse
    {
        try {
            $this->tiktok->handleCallback(
                (string) $request->query('state', ''),
                is_string($request->query('code')) ? $request->query('code') : null,
                is_string($request->query('error')) ? $request->query('error') : null,
            );
            $target = '/settings?tiktok=connected';
        } catch (TikTokOAuthException $e) {
            $reason = in_array($e->reason, ['denied', 'expired', 'already_linked', 'failed'], true)
                ? $e->reason
                : 'failed';
            $target = '/settings?tiktok=error&reason='.$reason;
        }

        return redirect()->away($this->frontend($target));
    }

    public function tiktokStatus(Request $request): JsonResponse
    {
        $account = $request->user()
            ->connectedAccounts()
            ->where('platform', 'tiktok')
            ->first();

        return response()->json([
            'data' => [
                'configured' => $this->tiktok->isConfigured(),
                'connected' => (bool) $account?->is_active,
                'display_name' => $account?->username,
                'avatar_url' => null,
                'connected_at' => $account?->created_at?->utc()->toIso8601String(),
                'needs_reconnect' => $account !== null && ! $account->is_active,
            ],
        ]);
    }

    public function tiktokDisconnect(Request $request): JsonResponse
    {
        $this->tiktok->disconnect($request->user());

        return response()->json([
            'data' => [
                'configured' => $this->tiktok->isConfigured(),
                'connected' => false,
                'display_name' => null,
                'avatar_url' => null,
                'connected_at' => null,
                'needs_reconnect' => false,
            ],
        ]);
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('services.frontend.url'), '/').$path;
    }

    private function safeNext(mixed $next): string
    {
        return in_array($next, ['/settings', '/onboarding'], true) ? $next : '/settings';
    }
}
