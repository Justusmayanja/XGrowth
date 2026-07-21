<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\GrowthAgent\GrowthAgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EvaluateAllGrowthOpportunitiesJob implements ShouldQueue
{
    use Queueable;

    public function handle(GrowthAgentService $growthAgent): void
    {
        $count = $growthAgent->evaluateAllUsers();
        Log::info('EvaluateAllGrowthOpportunitiesJob completed', ['users' => $count]);
    }
}
