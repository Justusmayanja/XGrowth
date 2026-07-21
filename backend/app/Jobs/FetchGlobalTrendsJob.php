<?php

namespace App\Jobs;

use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FetchGlobalTrendsJob implements ShouldQueue
{
    use Queueable;

    public function handle(TrendEngineService $trendEngine): void
    {
        $trends = $trendEngine->fetchGlobalTrends();
        Log::info('FetchGlobalTrendsJob completed', ['count' => count($trends)]);
    }
}
