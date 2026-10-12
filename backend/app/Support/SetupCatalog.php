<?php

namespace App\Support;

class SetupCatalog
{
    public const PLATFORMS = ['x', 'tiktok', 'youtube'];

    public const PLATFORM_NAMES = [
        'x' => 'X',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
    ];

    public const PURPOSES = [
        'business' => 'a business they run',
        'creator' => 'their brand or creator account',
        'side_hustle' => 'a side hustle, to start earning online',
        'exploring' => 'exploring what works before committing',
    ];

    public const GOALS = [
        'x' => [
            'grow_followers' => 'grow followers on X',
            'build_brand' => 'build a recognisable brand',
            'drive_traffic' => 'send people to a site or product',
            'promote_business' => 'promote the business',
            'influencer' => 'build influence in the niche',
            'monetize' => 'work toward X creator payouts',
        ],
        'tiktok' => [
            'for_you' => 'get clips onto the For You feed',
            'followers' => 'turn viewers into followers',
            'traffic' => 'send people to a link or product',
            'promote_business' => 'promote the business',
            'monetize' => 'work toward TikTok creator payouts',
        ],
        'youtube' => [
            'subscribers' => 'gain subscribers',
            'watch_time' => 'hold watch time',
            'search' => 'be found in YouTube search',
            'traffic' => 'send people to a site or product',
            'promote_business' => 'promote the business',
            'monetize' => 'work toward the YouTube Partner Program',
        ],
    ];

    public const NICHES = ['football', 'technology', 'business', 'crypto', 'motivation', 'memes', 'local_news', 'entertainment'];

    public const TONES = ['professional', 'passionate', 'funny', 'motivational', 'news'];

    public static function goalSentence(string $platform, array $goals): string
    {
        $labels = [];

        foreach ($goals as $goal) {
            $labels[] = self::GOALS[$platform][$goal] ?? str_replace('_', ' ', $goal);
        }

        return $labels === [] ? '' : implode(', ', $labels);
    }

    public static function platformForContentType(string $contentType): string
    {
        return match (true) {
            str_starts_with($contentType, 'tiktok') => 'tiktok',
            str_starts_with($contentType, 'youtube') => 'youtube',
            default => 'x',
        };
    }
}
