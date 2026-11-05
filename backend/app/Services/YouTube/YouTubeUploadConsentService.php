<?php

namespace App\Services\YouTube;

use App\Exceptions\YouTubeOAuthException;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Support\YouTubeOAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Second consent for youtube.upload. The read-only channel grant is unchanged
 * until this step returns a token that actually includes the upload scope.
 */
class YouTubeUploadConsentService
{
    public function __construct(
        private readonly YouTubeOAuthService $oauth,
        private readonly YouTubeChannelClient $client,
    ) {}

    public function authorizationUrl(User $user): string
    {
        if (! $this->oauth->isConfigured()) {
            throw new YouTubeOAuthException('not_configured');
        }

        $account = $this->account($user);
        if (! $account || ! $account->is_active) {
            throw new YouTubeOAuthException('needs_channel');
        }
        if ($this->granted($account)) {
            throw new YouTubeOAuthException('already_granted');
        }

        $state = Str::random(48);
        Cache::put($this->stateKey($state), [
            'user_id' => $user->id,
            'channel_id' => $account->platform_account_id,
        ], now()->addMinutes(YouTubeOAuth::STATE_TTL_MINUTES));

        return YouTubeOAuth::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.youtube.client_id'),
            'redirect_uri' => config('services.youtube.redirect'),
            'response_type' => 'code',
            'scope' => YouTubeOAuth::UPLOAD_SCOPE,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function recognizes(string $state): bool
    {
        if (strlen($state) < 40) {
            return false;
        }
        $cached = Cache::get($this->stateKey($state));

        return is_array($cached) && isset($cached['user_id'], $cached['channel_id']);
    }

    public function handleCallback(string $state, ?string $code, ?string $error): ConnectedAccount
    {
        $cached = $this->pullState($state);
        $user = User::query()->find($cached['user_id']);
        if (! $user || $user->is_blocked) {
            throw new YouTubeOAuthException('failed');
        }
        if ($error === 'access_denied') {
            throw new YouTubeOAuthException('denied');
        }
        if (filled($error) || ! filled($code)) {
            throw new YouTubeOAuthException('failed');
        }

        $account = ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->where('platform_account_id', $cached['channel_id'])
            ->first();
        if (! $account) {
            throw new YouTubeOAuthException('failed');
        }

        $token = $this->client->exchangeCode((string) $code);
        $returned = (string) ($token['scope'] ?? '');
        if (! str_contains($returned, YouTubeOAuth::UPLOAD_SCOPE)) {
            throw new YouTubeOAuthException('failed');
        }

        $channel = $this->client->channel((string) $token['access_token']);
        if ($channel['id'] !== $account->platform_account_id) {
            throw new YouTubeOAuthException('failed');
        }

        $account->forceFill([
            'username' => $channel['title'] !== '' ? $channel['title'] : $account->username,
            'access_token' => $token['access_token'],
            'refresh_token' => filled($token['refresh_token'] ?? null) ? $token['refresh_token'] : $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'is_active' => true,
            'scopes' => $this->mergeScopes((string) $account->scopes, $returned),
        ])->save();

        return $account->fresh() ?? $account;
    }

    public function granted(?ConnectedAccount $account): bool
    {
        if (! $account || ! $account->is_active || $account->platform !== 'youtube') {
            return false;
        }

        return in_array(YouTubeOAuth::UPLOAD_SCOPE, $this->scopeList((string) $account->scopes), true);
    }

    private function account(User $user): ?ConnectedAccount
    {
        return ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->first();
    }

    /**
     * @return array{user_id: int, channel_id: string}
     */
    private function pullState(string $state): array
    {
        if (strlen($state) < 40) {
            throw new YouTubeOAuthException('expired');
        }
        $cached = Cache::pull($this->stateKey($state));
        if (! is_array($cached) || ! isset($cached['user_id'], $cached['channel_id'])) {
            throw new YouTubeOAuthException('expired');
        }

        return [
            'user_id' => (int) $cached['user_id'],
            'channel_id' => (string) $cached['channel_id'],
        ];
    }

    private function mergeScopes(string $existing, string $returned): string
    {
        $scopes = array_merge($this->scopeList($existing), $this->scopeList($returned));
        $scopes = array_values(array_unique(array_filter($scopes)));

        return implode(' ', $scopes);
    }

    /**
     * @return list<string>
     */
    private function scopeList(string $scopes): array
    {
        $parts = preg_split('/[\s,]+/', trim($scopes)) ?: [];

        return array_values(array_filter($parts, fn ($scope) => is_string($scope) && $scope !== ''));
    }

    private function stateKey(string $state): string
    {
        return 'youtube-upload-consent.'.$state;
    }
}
