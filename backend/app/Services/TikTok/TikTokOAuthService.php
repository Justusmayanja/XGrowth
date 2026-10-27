<?php

namespace App\Services\TikTok;

use App\Exceptions\TikTokOAuthException;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Support\TikTokOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One TikTok account per user. Connecting a different account replaces this user's
 * previous TikTok row. How many accounts a tier may link is a later concern.
 *
 * A row with is_active false is kept on purpose: that is "needs reconnect".
 * Disconnect deletes the row. There is no status column on connected_accounts.
 */
class TikTokOAuthService
{
    public function isConfigured(): bool
    {
        return filled(config('services.tiktok.client_key'))
            && filled(config('services.tiktok.client_secret'))
            && filled(config('services.tiktok.redirect'));
    }

    public function authorizationUrl(User $user): string
    {
        if (! $this->isConfigured()) {
            throw new TikTokOAuthException('not_configured');
        }

        $verifier = $this->verifier();
        $state = Str::random(48);
        Cache::put($this->stateKey($state), [
            'user_id' => $user->id,
            'verifier' => $verifier,
        ], now()->addMinutes(TikTokOAuth::STATE_TTL_MINUTES));

        $query = http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'scope' => $this->scopes(),
            'response_type' => 'code',
            'redirect_uri' => config('services.tiktok.redirect'),
            'state' => $state,
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => TikTokOAuth::CHALLENGE_METHOD,
        ]);

        return TikTokOAuth::AUTHORIZE_URL.'?'.$query;
    }

    public function handleCallback(string $state, ?string $code, ?string $error): ConnectedAccount
    {
        $cached = $this->pullState($state);
        $user = User::query()->find($cached['user_id']);
        if (! $user || $user->is_blocked) {
            throw new TikTokOAuthException('failed');
        }

        if ($error === 'access_denied') {
            throw new TikTokOAuthException('denied');
        }
        if (is_string($error) && $error !== '') {
            throw new TikTokOAuthException('failed');
        }
        if (! is_string($code) || $code === '') {
            throw new TikTokOAuthException('failed');
        }

        $token = $this->exchangeCode($code, $cached['verifier']);
        $profile = $this->userInfo($token['access_token'], (string) ($token['open_id'] ?? ''));

        return $this->store($user, $token, $profile);
    }

    public function ensureFresh(ConnectedAccount $account): ConnectedAccount
    {
        if ($account->platform !== 'tiktok' || ! $account->is_active) {
            return $account;
        }

        $expires = $account->token_expires_at;
        if ($expires && $expires->greaterThan(now()->addMinutes(TikTokOAuth::REFRESH_WITHIN_MINUTES))) {
            return $account;
        }

        return $this->refresh($account);
    }

    public function refresh(ConnectedAccount $account): ConnectedAccount
    {
        if ($account->platform !== 'tiktok') {
            return $account;
        }

        $refreshToken = $account->refresh_token;
        if (! is_string($refreshToken) || $refreshToken === '') {
            $this->markReconnect($account);

            return $account->fresh() ?? $account;
        }

        $response = $this->http()->asForm()->post(TikTokOAuth::TOKEN_URL, [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $error = $this->oauthError($response);
        if ($this->needsReconnect($error)) {
            $this->markReconnect($account);

            return $account->fresh() ?? $account;
        }
        if ($error !== null || ! is_string($response->json('access_token'))) {
            throw new TikTokOAuthException('failed');
        }

        $body = $response->json();
        $account->forceFill([
            'access_token' => $body['access_token'],
            'refresh_token' => is_string($body['refresh_token'] ?? null) && $body['refresh_token'] !== ''
                ? $body['refresh_token']
                : $refreshToken,
            'token_expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 86400)),
            'is_active' => true,
        ])->save();

        return $account->fresh() ?? $account;
    }

    /**
     * @return array{refreshed: int, failed: int}
     */
    public function refreshExpiring(): array
    {
        $summary = ['refreshed' => 0, 'failed' => 0];
        $horizon = now()->addHours(TikTokOAuth::REFRESH_JOB_WITHIN_HOURS);

        ConnectedAccount::query()
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->where(function ($query) use ($horizon) {
                $query->whereNull('token_expires_at')->orWhere('token_expires_at', '<=', $horizon);
            })
            ->orderBy('id')
            ->each(function (ConnectedAccount $account) use (&$summary) {
                try {
                    $before = $account->token_expires_at?->timestamp;
                    $fresh = $this->refresh($account);
                    if ($fresh->is_active && $fresh->token_expires_at?->timestamp !== $before) {
                        $summary['refreshed']++;
                    } elseif (! $fresh->is_active) {
                        $summary['failed']++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('TikTok token refresh failed', [
                        'connected_account_id' => $account->id,
                        'error' => $e::class,
                    ]);
                    $summary['failed']++;
                }
            });

        return $summary;
    }

    public function disconnect(User $user): void
    {
        $account = ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'tiktok')
            ->first();
        if (! $account) {
            return;
        }

        $token = $account->access_token;
        if (is_string($token) && $token !== '' && $this->isConfigured()) {
            try {
                $this->http()->asForm()->post(TikTokOAuth::REVOKE_URL, [
                    'client_key' => config('services.tiktok.client_key'),
                    'client_secret' => config('services.tiktok.client_secret'),
                    'token' => $token,
                ]);
            } catch (\Throwable $e) {
                Log::warning('TikTok revoke failed', [
                    'connected_account_id' => $account->id,
                    'error' => $e::class,
                ]);
            }
        }

        $account->delete();
    }

    /**
     * @param  array<string, mixed>  $token
     * @param  array{open_id: string, display_name: ?string}  $profile
     */
    private function store(User $user, array $token, array $profile): ConnectedAccount
    {
        $openId = $profile['open_id'];
        $taken = ConnectedAccount::query()
            ->where('platform', 'tiktok')
            ->where('platform_account_id', $openId)
            ->where('user_id', '!=', $user->id)
            ->exists();
        if ($taken) {
            throw new TikTokOAuthException('already_linked');
        }

        return DB::transaction(function () use ($user, $token, $profile, $openId) {
            ConnectedAccount::query()
                ->where('user_id', $user->id)
                ->where('platform', 'tiktok')
                ->where('platform_account_id', '!=', $openId)
                ->delete();

            return ConnectedAccount::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => 'tiktok',
                    'platform_account_id' => $openId,
                ],
                [
                    'username' => $profile['display_name'],
                    'access_token' => $token['access_token'],
                    'refresh_token' => is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : null,
                    'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 86400)),
                    'is_active' => true,
                ],
            );
        });
    }

    /**
     * @return array{user_id: int, verifier: string}
     */
    private function pullState(string $state): array
    {
        if (strlen($state) < 40) {
            throw new TikTokOAuthException('expired');
        }

        $cached = Cache::pull($this->stateKey($state));
        if (! is_array($cached) || ! isset($cached['user_id'], $cached['verifier']) || ! is_string($cached['verifier'])) {
            throw new TikTokOAuthException('expired');
        }

        return [
            'user_id' => (int) $cached['user_id'],
            'verifier' => $cached['verifier'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $code, string $verifier): array
    {
        $response = $this->http()->asForm()->post(TikTokOAuth::TOKEN_URL, [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.tiktok.redirect'),
            'code_verifier' => $verifier,
        ]);

        if ($this->oauthError($response) !== null || ! is_string($response->json('access_token'))) {
            throw new TikTokOAuthException('failed');
        }

        return $response->json();
    }

    /**
     * @return array{open_id: string, display_name: ?string}
     */
    private function userInfo(string $accessToken, string $fallbackOpenId): array
    {
        $response = $this->http()
            ->withToken($accessToken)
            ->get(TikTokOAuth::USER_INFO_URL, [
                'fields' => TikTokOAuth::USER_FIELDS,
            ]);

        $errorCode = (string) ($response->json('error.code') ?? '');
        $user = $response->json('data.user');
        if ($response->failed() || ($errorCode !== '' && $errorCode !== 'ok') || ! is_array($user)) {
            throw new TikTokOAuthException('failed');
        }

        $openId = is_string($user['open_id'] ?? null) && $user['open_id'] !== ''
            ? $user['open_id']
            : $fallbackOpenId;
        if ($openId === '') {
            throw new TikTokOAuthException('failed');
        }

        $name = isset($user['display_name']) ? trim((string) $user['display_name']) : '';

        return [
            'open_id' => $openId,
            'display_name' => $name !== '' ? $name : null,
        ];
    }

    private function markReconnect(ConnectedAccount $account): void
    {
        $account->forceFill(['is_active' => false])->save();
    }

    private function needsReconnect(?string $error): bool
    {
        return in_array($error, ['invalid_grant', 'invalid_refresh_token'], true);
    }

    private function oauthError(Response $response): ?string
    {
        $error = $response->json('error');
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if ($response->serverError()) {
            return 'server_error';
        }
        if ($response->failed()) {
            return 'failed';
        }

        return null;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)
            ->retry(3, 100, function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException && $exception->response->serverError();
            }, throw: false);
    }

    private function scopes(): string
    {
        $scopes = trim((string) config('services.tiktok.scopes'));

        return $scopes !== '' ? $scopes : TikTokOAuth::DEFAULT_SCOPES;
    }

    private function verifier(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $bytes = random_bytes(64);
        $verifier = '';
        for ($i = 0; $i < 64; $i++) {
            $verifier .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        }

        return $verifier;
    }

    private function challenge(string $verifier): string
    {
        return hash('sha256', $verifier);
    }

    private function stateKey(string $state): string
    {
        return 'tiktok-oauth-state.'.$state;
    }
}
