<?php

namespace App\Services\YouTube;

use App\Support\YouTubeOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeDataClient
{
    /** @return list<array{channel: string, title: string, views: int, url: string, duration: int, youtube_video_id: string}> */
    public function channelVideos(string $accessToken, string $channelId, int $limit = 25): array
    {
        $channel = $this->get($accessToken, YouTubeOAuth::CHANNELS_URL, [
            'part' => 'contentDetails',
            'id' => $channelId,
        ]);
        $playlist = $channel['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if (! is_string($playlist) || $playlist === '') {
            throw new RuntimeException('YouTube did not return the channel uploads playlist.');
        }

        $items = $this->get($accessToken, YouTubeOAuth::PLAYLIST_ITEMS_URL, [
            'part' => 'contentDetails',
            'playlistId' => $playlist,
            'maxResults' => max(1, min(50, $limit)),
        ]);
        $ids = collect($items['items'] ?? [])
            ->pluck('contentDetails.videoId')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        return $this->videoDetails($accessToken, $ids);
    }

    /** @return list<array{channel: string, title: string, views: int, url: string, duration: int, youtube_video_id: string}> */
    public function search(string $accessToken, string $query, int $limit = 20): array
    {
        $body = $this->get($accessToken, YouTubeOAuth::SEARCH_URL, [
            'part' => 'id',
            'type' => 'video',
            'q' => $query,
            'maxResults' => max(4, min(25, $limit)),
            'order' => 'viewCount',
            'safeSearch' => 'moderate',
        ]);
        $ids = collect($body['items'] ?? [])
            ->pluck('id.videoId')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        return $this->videoDetails($accessToken, $ids);
    }

    /** @return list<string> */
    public function comments(string $accessToken, string $videoId, int $limit = 100): array
    {
        $body = $this->get($accessToken, YouTubeOAuth::COMMENTS_URL, [
            'part' => 'snippet',
            'videoId' => $videoId,
            'maxResults' => max(1, min(100, $limit)),
            'order' => 'relevance',
            'textFormat' => 'plainText',
        ]);

        return collect($body['items'] ?? [])
            ->map(fn ($item) => trim((string) ($item['snippet']['topLevelComment']['snippet']['textDisplay'] ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{channel: string, title: string, views: int, url: string, duration: int, youtube_video_id: string}>
     */
    private function videoDetails(string $accessToken, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $body = $this->get($accessToken, YouTubeOAuth::VIDEOS_URL, [
            'part' => 'snippet,statistics,contentDetails',
            'id' => implode(',', array_slice($ids, 0, 50)),
        ]);

        return collect($body['items'] ?? [])->map(function ($item) {
            $id = (string) ($item['id'] ?? '');

            return [
                'channel' => trim((string) ($item['snippet']['channelTitle'] ?? '')) ?: 'YouTube channel',
                'title' => trim((string) ($item['snippet']['title'] ?? '')) ?: 'Untitled video',
                'views' => max(0, (int) ($item['statistics']['viewCount'] ?? 0)),
                'url' => 'https://www.youtube.com/watch?v='.$id,
                'duration' => $this->durationSeconds((string) ($item['contentDetails']['duration'] ?? '')),
                'youtube_video_id' => $id,
            ];
        })->filter(fn ($item) => $item['youtube_video_id'] !== '')->values()->all();
    }

    /** @return array<string, mixed> */
    private function get(string $accessToken, string $url, array $query): array
    {
        $response = $this->http()->withToken($accessToken)->get($url, $query);
        $body = $response->json();
        if ($response->failed() || ! is_array($body)) {
            $reason = (string) ($response->json('error.message') ?? '');
            throw new RuntimeException($reason !== '' ? $reason : 'YouTube Data could not complete the read.');
        }

        return $body;
    }

    private function durationSeconds(string $duration): int
    {
        try {
            $interval = new \DateInterval($duration);

            return ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->retry(3, 100, function ($exception) {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError());
        }, throw: false);
    }
}
