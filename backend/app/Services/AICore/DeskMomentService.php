<?php

namespace App\Services\AICore;

use App\Models\AccountProfile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DeskMomentService
{
    public function __construct(
        private readonly AICoreService $ai,
        private readonly AiUsageService $usage,
    ) {}

    /**
     * A short-lived set of moments. The next set is asked to avoid this one.
     *
     * @param  list<string>  $recentPosts
     * @return list<array{name: string, suggestion: string}>
     */
    public function forProfile(User $user, AccountProfile $profile, string $platform, array $recentPosts = []): array
    {
        $key = "desk-moments:{$user->id}:{$platform}";
        $cached = Cache::get($key);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $previous = Cache::get("{$key}:previous");
        $previous = is_array($previous) ? $previous : [];

        if (! $this->ai->isConfigured() || ! $this->usage->canUse($user, AiUsageService::ACTION_GENERATION)) {
            return $previous;
        }

        try {
            $ideas = $this->ai->suggestMoments($user, $profile, $platform, $previous, $recentPosts);
        } catch (Throwable) {
            return $previous;
        }

        if ($ideas === []) {
            return $previous;
        }

        Cache::put($key, $ideas, now()->addMinutes(12));
        Cache::put("{$key}:previous", $ideas, now()->addDays(14));

        return $ideas;
    }
}
