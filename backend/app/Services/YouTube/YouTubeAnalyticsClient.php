<?php

namespace App\Services\YouTube;

use App\Support\YouTubeOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeAnalyticsClient
{
    /** @return array{views: int, average_view_percent: float} */
    public function videoResults(string $accessToken, string $videoId): array
    {
        $body = $this->report($accessToken, [
            'metrics' => 'views,averageViewPercentage',
            'filters' => 'video=='.$videoId,
        ]);
        $row = $body['rows'][0] ?? null;
        if (! is_array($row)) {
            throw new RuntimeException('YouTube Analytics has no results for that video in this date range.');
        }

        return [
            'views' => max(0, (int) ($row[0] ?? 0)),
            'average_view_percent' => max(0, min(100, (float) ($row[1] ?? 0))),
        ];
    }

    /** @return array{csv: string, duration: float|null, points: int} */
    public function retention(string $accessToken, string $videoId): array
    {
        $body = $this->report($accessToken, [
            'metrics' => 'audienceWatchRatio',
            'dimensions' => 'elapsedVideoTimeRatio',
            'filters' => 'video=='.$videoId,
            'sort' => 'elapsedVideoTimeRatio',
        ]);
        $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
        $lines = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row[0] ?? null) && is_numeric($row[1] ?? null)) {
                $lines[] = round((float) $row[0] * 100, 4).','.round((float) $row[1] * 100, 4);
            }
        }

        return ['csv' => implode("\n", $lines), 'duration' => null, 'points' => count($lines)];
    }

    /** @return array<string, mixed> */
    private function report(string $accessToken, array $query): array
    {
        $end = now()->utc()->toDateString();
        $response = $this->http()->withToken($accessToken)->get(YouTubeOAuth::ANALYTICS_URL, [
            'ids' => 'channel==MINE',
            'startDate' => now()->utc()->subYears(10)->toDateString(),
            'endDate' => $end,
            ...$query,
        ]);
        $body = $response->json();
        if ($response->failed() || ! is_array($body)) {
            $reason = (string) ($response->json('error.message') ?? '');
            throw new RuntimeException($reason !== '' ? $reason : 'YouTube Analytics could not complete the read.');
        }

        return $body;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->retry(3, 100, function ($exception) {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError());
        }, throw: false);
    }
}
