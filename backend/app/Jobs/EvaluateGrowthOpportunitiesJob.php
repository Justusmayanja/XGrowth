<?php

namespace App\Jobs;

use App\Services\GrowthAgent\GrowthAgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EvaluateGrowthOpportunitiesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(GrowthAgentService $growthAgent): void
    {
        Log::info('EvaluateGrowthOpportunitiesJob started', ['user_id' => $this->userId]);

        // Placeholder until full Growth Agent brain is wired
        $growthAgent->evaluateOpportunities($this->userId);
    }
}
