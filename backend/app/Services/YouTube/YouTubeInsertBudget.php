<?php

namespace App\Services\YouTube;

use App\Models\User;
use App\Models\YouTubeInsertAttempt;
use App\Models\YouTubeVideo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class YouTubeInsertBudget
{
    /**
     * Project-wide videos.insert attempts for the current UTC day.
     * The default is 100, shared by every user. It is not the monthly allowance.
     */
    public function limit(): int
    {
        return max(0, (int) config('services.youtube.upload_daily_budget', 100));
    }

    public function used(): int
    {
        return YouTubeInsertAttempt::query()
            ->whereDate('reserved_on', $this->today())
            ->count();
    }

    public function remaining(): int
    {
        return max(0, $this->limit() - $this->used());
    }

    public function resetsOn(): string
    {
        return CarbonImmutable::now('UTC')->addDay()->toDateString();
    }

    public function reserve(User $user, YouTubeVideo $video): bool
    {
        return DB::transaction(function () use ($user, $video) {
            $used = YouTubeInsertAttempt::query()
                ->whereDate('reserved_on', $this->today())
                ->count();
            if ($used >= $this->limit()) {
                return false;
            }

            YouTubeInsertAttempt::query()->create([
                'user_id' => $user->id,
                'youtube_video_id' => $video->id,
                'reserved_on' => $this->today(),
            ]);

            return true;
        });
    }

    private function today(): string
    {
        return CarbonImmutable::now('UTC')->toDateString();
    }
}
