<?php

namespace App\Services\YouTube;

use App\Exceptions\YouTubeOAuthException;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Support\YouTubeOAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class YouTubeOAuthService
{
    public function __construct(private readonly YouTubeChannelClient $client) {}

    public function isConfigured(): bool
    {
        return filled(config('services.youtube.client_id'))
            && filled(config('services.youtube.client_secret'))
            && filled(config('services.youtube.redirect'));
    }

    public function authorizationUrl(User $user): string
    {
        if (! $this->isConfigured()) {
            throw new YouTubeOAuthException('not_configured');
        }

        $state = Str::random(48);
        Cache::put($this->stateKey($state), ['user_id' => $user->id], now()->addMinutes(YouTubeOAuth::STATE_TTL_MINUTES));

        return YouTubeOAuth::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.youtube.client_id'),
            'redirect_uri' => config('services.youtube.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);
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

        $token = $this->client->exchangeCode((string) $code);
        $channel = $this->client->channel($token['access_token']);

        return $this->store($user, $token, $channel);
    }

    public function ensureFresh(ConnectedAccount $account): ConnectedAccount
    {
        if ($account->platform !== 'youtube' || ! $account->is_active) {
            return $account;
        }
        if ($account->token_expires_at?->greaterThan(now()->addMinutes(YouTubeOAuth::REFRESH_WITHIN_MINUTES))) {
            return $account;
        }
        if (! filled($account->refresh_token)) {
            $account->forceFill(['is_active' => false])->save();

            throw new YouTubeOAuthException('needs_reconnect');
        }

        try {
            $token = $this->client->refreshToken($account->refresh_token);
        } catch (YouTubeOAuthException $e) {
            if ($e->reason === 'needs_reconnect') {
                $account->forceFill(['is_active' => false])->save();
            }
            throw $e;
        }

        $account->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => filled($token['refresh_token'] ?? null) ? $token['refresh_token'] : $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes' => filled($token['scope'] ?? null) ? $token['scope'] : $account->scopes,
            'is_active' => true,
        ])->save();

        return $account->fresh() ?? $account;
    }

    /**
     * @return array{subscribers: int|null, views: int|null, watch_hours: float|null, error: string|null}
     */
    public function metrics(ConnectedAccount $account): array
    {
        try {
            $account = $this->ensureFresh($account);
            $channel = $this->client->channel((string) $account->access_token);
            $analytics = $this->client->analytics((string) $account->access_token);

            return [
                'subscribers' => $channel['subscribers'],
                'views' => $analytics['views'],
                'watch_hours' => $analytics['watch_minutes'] === null ? null : round($analytics['watch_minutes'] / 60, 1),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('YouTube channel metrics failed', [
                'connected_account_id' => $account->id,
                'error' => $e::class,
            ]);

            return [
                'subscribers' => null,
                'views' => null,
                'watch_hours' => null,
                'error' => $e instanceof YouTubeOAuthException && $e->reason === 'needs_reconnect'
                    ? 'Reconnect YouTube to load channel metrics.'
                    : 'YouTube could not load channel metrics right now.',
            ];
        }
    }

    public function disconnect(User $user): void
    {
        $account = ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->first();
        if (! $account) {
            return;
        }

        $token = filled($account->refresh_token) ? $account->refresh_token : $account->access_token;
        if (filled($token) && $this->isConfigured()) {
            try {
                $this->client->revoke((string) $token);
            } catch (\Throwable $e) {
                Log::warning('YouTube revoke failed', [
                    'connected_account_id' => $account->id,
                    'error' => $e::class,
                ]);
            }
        }

        $account->delete();
    }

    /**
     * @param  array<string, mixed>  $token
     * @param  array{id: string, title: string, subscribers: int|null, views: int|null}  $channel
     */
    private function store(User $user, array $token, array $channel): ConnectedAccount
    {
        $taken = ConnectedAccount::query()
            ->where('platform', 'youtube')
            ->where('platform_account_id', $channel['id'])
            ->where('user_id', '!=', $user->id)
            ->exists();
        if ($taken) {
            throw new YouTubeOAuthException('already_linked');
        }

        return DB::transaction(function () use ($user, $token, $channel) {
            ConnectedAccount::query()
                ->where('user_id', $user->id)
                ->where('platform', 'youtube')
                ->where('platform_account_id', '!=', $channel['id'])
                ->delete();

            $existing = ConnectedAccount::query()
                ->where('user_id', $user->id)
                ->where('platform', 'youtube')
                ->where('platform_account_id', $channel['id'])
                ->first();
            $refresh = filled($token['refresh_token'] ?? null) ? $token['refresh_token'] : $existing?->refresh_token;

            return ConnectedAccount::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => 'youtube',
                    'platform_account_id' => $channel['id'],
                ],
                [
                    'username' => $channel['title'],
                    'access_token' => $token['access_token'],
                    'refresh_token' => $refresh,
                    'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                    'is_active' => true,
                    'scopes' => filled($token['scope'] ?? null) ? $token['scope'] : implode(' ', $this->scopes()),
                ],
            );
        });
    }

    /**
     * @return array{user_id: int}
     */
    private function pullState(string $state): array
    {
        if (strlen($state) < 40) {
            throw new YouTubeOAuthException('expired');
        }
        $cached = Cache::pull($this->stateKey($state));
        if (! is_array($cached) || ! isset($cached['user_id'])) {
            throw new YouTubeOAuthException('expired');
        }

        return ['user_id' => (int) $cached['user_id']];
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $allowed = [YouTubeOAuth::READONLY_SCOPE, YouTubeOAuth::ANALYTICS_READONLY_SCOPE];
        $configured = preg_split('/[\s,]+/', trim((string) config('services.youtube.scopes'))) ?: [];
        $scopes = array_values(array_intersect($configured, $allowed));

        return $scopes !== [] ? $scopes : $allowed;
    }

    private function stateKey(string $state): string
    {
        return 'youtube-oauth-state.'.$state;
    }
}
