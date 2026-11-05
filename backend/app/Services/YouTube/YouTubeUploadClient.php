<?php

namespace App\Services\YouTube;

use App\Support\YouTubeOAuth;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class YouTubeUploadClient
{
    /**
     * Resumable videos.insert. The caller has already checked the local file.
     *
     * @param  array{title: string, description: string, tags: list<string>}  $snippet
     * @return array{id: string, privacy: string}
     */
    public function insert(string $accessToken, string $absolutePath, string $mime, array $snippet, string $privacy): array
    {
        if (! in_array($privacy, ['private', 'public', 'unlisted'], true)) {
            throw new YouTubeUploadException('The visibility to request is not valid. Nothing was posted.');
        }

        $size = filesize($absolutePath);
        if ($size === false || $size < 1) {
            throw new YouTubeUploadException('The local file is empty. Nothing was posted.');
        }

        $session = $this->request($accessToken)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $size,
                'X-Upload-Content-Type' => $mime,
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->post(YouTubeOAuth::RESUMABLE_UPLOAD_URL.'?uploadType=resumable&part=snippet,status', [
                'snippet' => [
                    'title' => $snippet['title'],
                    'description' => $snippet['description'],
                    'tags' => array_values($snippet['tags']),
                    'categoryId' => '22',
                ],
                'status' => [
                    'privacyStatus' => $privacy,
                    'selfDeclaredMadeForKids' => false,
                ],
            ]);

        $location = $session->header('Location');
        if (! $session->successful() || ! is_string($location) || $location === '') {
            throw new YouTubeUploadException('YouTube did not start the upload. The video stays queued and can be sent again.');
        }
        $this->assertSessionUrl($location);

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new YouTubeUploadException('The local file could not be read. Nothing was posted.');
        }

        try {
            $offset = 0;
            $chunk = 8 * 1024 * 1024;
            for ($attempt = 0; $attempt < 8 && $offset < $size; $attempt++) {
                $length = (int) min($chunk, $size - $offset);
                if (fseek($handle, $offset) !== 0) {
                    throw new YouTubeUploadException('The local file could not be read. Nothing was posted.');
                }
                $bytes = fread($handle, $length);
                if (! is_string($bytes) || strlen($bytes) !== $length) {
                    throw new YouTubeUploadException('The local file could not be read. Nothing was posted.');
                }
                $end = $offset + $length - 1;
                $response = $this->request($accessToken)
                    ->withHeaders([
                        'Content-Length' => (string) $length,
                        'Content-Type' => $mime,
                        'Content-Range' => 'bytes '.$offset.'-'.$end.'/'.$size,
                    ])
                    ->withBody($bytes, $mime)
                    ->put($location);

                if ($response->status() === 308) {
                    $next = $this->resumeOffset($response, $size, $offset + $length);
                    if ($next <= $offset) {
                        throw new YouTubeUploadException('YouTube paused the upload and did not advance. The video stays queued and can be sent again.');
                    }
                    $offset = $next;

                    continue;
                }

                if ($response->successful()) {
                    return $this->resource($response);
                }

                throw new YouTubeUploadException('YouTube did not accept the video. It stays queued and can be sent again.');
            }
        } finally {
            fclose($handle);
        }

        throw new YouTubeUploadException('YouTube did not finish the upload. It stays queued and can be sent again.');
    }

    /**
     * captions.list, then one track download. Null keeps the local or pasted transcript.
     */
    public function captions(string $accessToken, string $videoId): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) !== 1) {
            return null;
        }

        try {
            $list = $this->request($accessToken)->get(YouTubeOAuth::CAPTIONS_URL, [
                'part' => 'snippet',
                'videoId' => $videoId,
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $list->successful()) {
            return null;
        }

        $captionId = $list->json('items.0.id');
        if (! is_string($captionId) || $captionId === '') {
            return null;
        }

        try {
            $download = $this->request($accessToken)->get(YouTubeOAuth::CAPTIONS_URL.'/'.$captionId, [
                'tfmt' => 'srt',
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $download->successful()) {
            return null;
        }

        $text = trim($download->body());

        return $text !== '' ? $text : null;
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->timeout(120)
            ->connectTimeout(10);
    }

    private function assertSessionUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            throw new YouTubeUploadException('YouTube returned an upload address this server will not use. Nothing was posted.');
        }
        if (app()->runningUnitTests()) {
            return;
        }

        $allowed = str_ends_with($host, 'googleapis.com') || str_ends_with($host, 'googleusercontent.com');
        if (! $allowed) {
            throw new YouTubeUploadException('YouTube returned an upload address this server will not use. Nothing was posted.');
        }
    }

    private function resumeOffset(Response $response, int $size, int $fallback): int
    {
        $range = $response->header('Range');
        if (is_string($range) && preg_match('/bytes=\d+-(\d+)/', $range, $match) === 1) {
            $next = ((int) $match[1]) + 1;
            if ($next > 0 && $next < $size) {
                return $next;
            }
        }
        if ($fallback > 0 && $fallback < $size) {
            return $fallback;
        }

        throw new YouTubeUploadException('YouTube paused the upload and did not say where to resume. The video stays queued and can be sent again.');
    }

    /**
     * @return array{id: string, privacy: string}
     */
    private function resource(Response $response): array
    {
        $id = $response->json('id');
        $privacy = $response->json('status.privacyStatus');
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) !== 1) {
            throw new YouTubeUploadException('YouTube did not return a video id. The package stays queued and was not marked posted.');
        }
        if (! is_string($privacy) || ! in_array($privacy, ['private', 'public', 'unlisted'], true)) {
            throw new YouTubeUploadException('YouTube did not return the visibility it applied. The package stays queued and was not marked posted.');
        }

        return ['id' => $id, 'privacy' => $privacy];
    }
}
