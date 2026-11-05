<?php

namespace App\Services\YouTube;

use App\Exceptions\YouTubeOAuthException;
use App\Support\YouTubeOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class YouTubeChannelClient
{
    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->http()->asForm()->post(YouTubeOAuth::TOKEN_URL, [
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.youtube.redirect'),
        ]);

        $body = $response->json();
        if ($response->failed() || ! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            throw new YouTubeOAuthException('failed');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = $this->http()->asForm()->post(YouTubeOAuth::TOKEN_URL, [
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $body = $response->json();
        if ($response->failed() || ! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            throw new YouTubeOAuthException(
                in_array((string) ($body['error'] ?? ''), ['invalid_grant', 'invalid_token'], true)
                    ? 'needs_reconnect'
                    : 'failed'
            );
        }

        return $body;
    }

    /**
     * @return array{id: string, title: string, subscribers: int|null, views: int|null}
     */
    public function channel(string $accessToken): array
    {
        $response = $this->http()->withToken($accessToken)->get(YouTubeOAuth::CHANNELS_URL, [
            'part' => 'snippet,statistics',
            'mine' => 'true',
        ]);
        $channel = $response->json('items.0');
        if ($response->failed() || ! is_array($channel) || ! is_string($channel['id'] ?? null)) {
            throw new YouTubeOAuthException('channel_unavailable');
        }

        return [
            'id' => $channel['id'],
            'title' => trim((string) ($channel['snippet']['title'] ?? '')) ?: 'YouTube channel',
            'subscribers' => $this->integerOrNull($channel['statistics']['subscriberCount'] ?? null),
            'views' => $this->integerOrNull($channel['statistics']['viewCount'] ?? null),
        ];
    }

    /**
     * @return array{watch_minutes: float|null, views: int|null}
     */
    public function analytics(string $accessToken, ?Carbon $today = null): array
    {
        $end = ($today ?? now())->copy()->utc()->startOfDay();
        $start = $end->copy()->subDays(27);
        $response = $this->http()->withToken($accessToken)->get(YouTubeOAuth::ANALYTICS_URL, [
            'ids' => 'channel==MINE',
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'metrics' => 'estimatedMinutesWatched,views',
        ]);
        $row = $response->json('rows.0');
        if ($response->failed() || (! is_array($row) && $response->json('rows') !== [])) {
            throw new YouTubeOAuthException('analytics_unavailable');
        }

        return [
            'watch_minutes' => is_array($row) && is_numeric($row[0] ?? null) ? (float) $row[0] : 0.0,
            'views' => is_array($row) && is_numeric($row[1] ?? null) ? (int) $row[1] : 0,
        ];
    }

    public function revoke(string $token): void
    {
        $this->http()->asForm()->post(YouTubeOAuth::REVOKE_URL, ['token' => $token]);
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(15)
            ->retry(3, 100, function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException && $exception->response->serverError();
            }, throw: false);
    }
}
