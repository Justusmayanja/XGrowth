<?php

namespace App\Services\XApi;

class XApiService
{
    /**
     * X API v2 wrapper — integration deferred to final stage.
     */
    public function postTweet(int $connectedAccountId, string $content): array
    {
        return [
            'success' => false,
            'message' => 'X API not configured yet.',
        ];
    }

    public function getTrends(): array
    {
        return [];
    }
}
