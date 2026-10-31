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
            return response()->json($this->xPayload($request, true, $account->username));
        }

        if (! $this->x->configured()) {
            return response()->json($this->xPayload($request, false, null, 'X sign-in is not turned on for this server yet.', false));
        }

        $next = $this->safeNext($request->query('next'));

        return response()->json($this->xPayload(
            $request,
            false,
            null,
            'Connect X to publish and read live totals.',
            true,
            $this->x->authorizationUrl([
                'provider' => 'x',
                'purpose' => 'connect',
                'user_id' => $request->user()->id,
                'next' => $next,
            ]),
        ));
    }

    public function updateVoice(Request $request, int $account): JsonResponse
    {
        $row = $request->user()
            ->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->whereKey($account)
            ->first();

        if (! $row) {
            return response()->json([
                'error' => ['message' => 'That X account is not connected.'],
            ], 404);
        }

        $data = $request->validate([
            'niche' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'audience' => ['nullable', 'string', 'max:200'],
        ]);

        $row->update([
            'voice_niche' => $this->blankToNull($data['niche'] ?? null),
            'voice_description' => $this->blankToNull($data['description'] ?? null),
            'voice_audience' => $this->blankToNull($data['audience'] ?? null),
        ]);

        return response()->json($this->xPayload($request, true, $row->username));
    }

    public function disconnect(Request $request): JsonResponse
    {
        $query = $request->user()->connectedAccounts()->where('platform', 'x');
        $id = $request->input('account', $request->query('account'));
        if (is_numeric($id)) {
            $query->whereKey((int) $id);
        }
        $query->delete();

        $left = $request->user()->connectedAccounts()->where('platform', 'x')->where('is_active', true)->orderBy('id')->first();

        return response()->json($left
            ? $this->xPayload($request, true, $left->username)
            : $this->xPayload($request, false, null, 'X is disconnected.', $this->x->configured()));
    }

    /**
     * @return array<string, mixed>
     */
    private function xPayload(Request $request, bool $connected, ?string $username, ?string $message = null, bool $available = true, ?string $authorizeUrl = null): array
    {
        $accounts = $request->user()->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'username', 'voice_niche', 'voice_description', 'voice_audience']);

        return [
            'available' => $available,
            'connected' => $connected,
            'username' => $username,
            'authorize_url' => $authorizeUrl,
            'message' => $message ?? ($username ? "@{$username} is connected." : 'Connect X to publish and read live totals.'),
            'accounts' => $accounts->map(fn ($account) => [
                'id' => (int) $account->id,
                'username' => $account->username,
                'niche' => (string) ($account->voice_niche ?? ''),
                'description' => (string) ($account->voice_description ?? ''),
                'audience' => (string) ($account->voice_audience ?? ''),
            ])->all(),
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
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
